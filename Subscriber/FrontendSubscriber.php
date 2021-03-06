<?php
/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/shopware-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/shopware-ee/blob/master/LICENSE
 */

namespace WirecardElasticEngine\Subscriber;

use Enlight\Event\SubscriberInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Shopware\Components\Theme\LessDefinition;
use Shopware\Models\Order\Order;
use Shopware\Models\Order\Status;
use WirecardElasticEngine\Components\Payments\Contracts\AdditionalPaymentInformationInterface;
use WirecardElasticEngine\Components\Mapper\UserMapper;
use WirecardElasticEngine\Components\Payments\Contracts\AdditionalViewAssignmentsInterface;
use WirecardElasticEngine\Components\Payments\Contracts\DisplayRestrictionInterface;
use WirecardElasticEngine\Components\Payments\RatepayInvoicePayment;
use WirecardElasticEngine\Components\Services\PaymentFactory;
use WirecardElasticEngine\Components\Services\SessionManager;
use WirecardElasticEngine\Exception\UnknownPaymentException;

/**
 * @package WirecardElasticEngine\Subscriber
 *
 * @since   1.0.0
 */
class FrontendSubscriber implements SubscriberInterface
{
    /**
     * @var string
     */
    private $pluginDirectory;

    /**
     * @var \Enlight_Template_Manager
     */
    private $templateManager;

    /**
     * @var PaymentFactory
     */
    private $paymentFactory;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @param string                    $pluginDirectory
     * @param \Enlight_Template_Manager $templateManager
     * @param PaymentFactory            $paymentFactory
     * @param EntityManagerInterface    $em
     *
     * @since 1.1.0 Added $em
     * @since 1.0.0
     */
    public function __construct(
        $pluginDirectory,
        \Enlight_Template_Manager $templateManager,
        PaymentFactory $paymentFactory,
        EntityManagerInterface $em
    ) {
        $this->pluginDirectory = $pluginDirectory;
        $this->templateManager = $templateManager;
        $this->paymentFactory  = $paymentFactory;
        $this->em              = $em;
    }

    /**
     * @return array
     *
     * @since 1.0.0
     */
    public static function getSubscribedEvents()
    {
        return [
            'Shopware_Modules_Admin_GetPaymentMeans_DataFilter'              => 'onGetPayments',
            'Enlight_Controller_Action_PreDispatch'                          => 'onPreDispatch',
            'Theme_Compiler_Collect_Plugin_Less'                             => 'onCollectLessFiles',
            'Enlight_Controller_Action_PostDispatchSecure_Frontend_Checkout' => 'onPostDispatchCheckout',
        ];
    }

    /**
     * Remove payments that implement DisplayRestrictionInterface and fail the checkDisplayRestrictions test
     * (e.g. RatepayInvoicePayment)
     *
     * @param \Enlight_Event_EventArgs $args
     *
     * @since 1.1.0
     */
    public function onGetPayments(\Enlight_Event_EventArgs $args)
    {
        /** @var \sAdmin $admin */
        $admin          = $args->get('subject');
        $userData       = $admin->sGetUserData();
        $userMapper     = new UserMapper($userData, '', '');
        $sessionManager = new SessionManager(\Shopware()->Session());

        $paymentMeans = $args->getReturn();
        foreach ($paymentMeans as $key => $paymentData) {
            try {
                $payment = $this->paymentFactory->create($paymentData['name']);
                if ($payment instanceof DisplayRestrictionInterface) {
                    if (! $payment->checkDisplayRestrictions($userMapper, $sessionManager)) {
                        unset($paymentMeans[$key]);
                    }
                }
            } catch (UnknownPaymentException $e) {
            }
        }

        $args->setReturn($paymentMeans);
    }

    /**
     * @param \Enlight_Controller_ActionEventArgs $args
     *
     * @since 1.0.0
     */
    public function onPreDispatch(\Enlight_Controller_ActionEventArgs $args)
    {
        $this->templateManager->addTemplateDir($this->pluginDirectory . '/Resources/views');
    }

    /**
     * @return ArrayCollection
     *
     * @since 1.0.0
     */
    public function onCollectLessFiles()
    {
        $less = new LessDefinition(
            [],
            [$this->pluginDirectory . '/Resources/views/frontend/_public/src/less/all.less'],
            $this->pluginDirectory
        );

        return new ArrayCollection([$less]);
    }

    /**
     * @param \Enlight_Controller_ActionEventArgs $args
     *
     * @throws \WirecardElasticEngine\Exception\UnknownPaymentException
     *
     * @since 1.0.0
     */
    public function onPostDispatchCheckout(\Enlight_Controller_ActionEventArgs $args)
    {
        $controller = $args->getSubject();
        $request    = $controller->Request();
        $view       = $controller->View();
        $params     = $request->getParams();

        // In case additional payment data is provided it is stored in the session and later passed to
        // the `OrderSummary` by the controller. Keep in mind that this additional payment data should be passed as
        // an array named `wirecardElasticEngine`.
        // Example: <input type="text" name="wirecardElasticEngine[yourAdditionalFieldName]">
        if (! empty($params['wirecardElasticEngine'])) {
            $sessionManager = $controller->get('wirecard_elastic_engine.session_manager');
            $sessionManager->storePaymentData($params['wirecardElasticEngine']);
        }

        // On checkout finish page
        if ($request->getActionName() === 'finish') {
            $this->assignPaymentStatus($view);
            $this->assignAdditionalPaymentInformation($view);
        }

        // On checkout confirm page
        if ($request->getActionName() === 'confirm') {
            $sessionManager = $controller->get('wirecard_elastic_engine.session_manager');

            // Assigns the device fingerprint id to the view in case the payment has fraud prevention enabled.
            $this->assignDeviceFingerprint($view, $sessionManager);

            // Some payments may require additional view assignments (e.g. SEPA input fields)
            $this->assignAdditionalViewAssignments($view, $sessionManager);
        }

        $errorCode = $request->getParam('wirecard_elastic_engine_error_code');
        if ($errorCode) {
            $view->assign('wirecardElasticEngineErrorCode', $errorCode);
            $view->assign('wirecardElasticEngineErrorMessage', $request->getParam('wirecard_elastic_engine_error_msg'));
        }

        if ($request->getParam('wirecard_elastic_engine_update_cart')) {
            $view->assign('wirecardElasticEngineUpdateCart', true);
        }
    }

    /**
     * @param \Enlight_View_Default $view
     *
     * @since 1.0.0
     */
    private function assignPaymentStatus(\Enlight_View_Default $view)
    {
        $payment     = $view->getAssign('sPayment');
        $orderNumber = $view->getAssign('sOrderNumber');
        if (! isset($payment['name'])
            || ! $this->paymentFactory->isSupportedPayment($payment['name'])
            || ! $orderNumber
        ) {
            return;
        }
        /** @var Order $order */
        $order = $this->em->getRepository(Order::class)->findOneBy(['number' => $orderNumber]);
        if (! $order) {
            return;
        }

        switch ($order->getPaymentStatus()->getId()) {
            case Status::PAYMENT_STATE_OPEN:
                $paymentStatus = 'pending';
                break;
            case Status::PAYMENT_STATE_THE_PROCESS_HAS_BEEN_CANCELLED:
                $paymentStatus = 'canceled';
                break;
            default:
                $paymentStatus = 'success';
        }

        $view->assign('wirecardElasticEnginePayment', true);
        $view->assign('wirecardElasticEnginePaymentStatus', $paymentStatus);
    }

    /**
     * Some payments may require additional info on checkout finish page (e.g. PIA).
     *
     * @param \Enlight_View_Default $view
     *
     * @throws \WirecardElasticEngine\Exception\UnknownPaymentException
     *
     * @since 1.1.0
     */
    private function assignAdditionalPaymentInformation(\Enlight_View_Default $view)
    {
        $payment = $view->getAssign('sPayment');
        if (! isset($payment['name']) || ! $this->paymentFactory->isSupportedPayment($payment['name'])) {
            return;
        }
        $paymentMethod = $this->paymentFactory->create($payment['name']);
        if ($paymentMethod instanceof AdditionalPaymentInformationInterface) {
            $paymentMethod->assignAdditionalPaymentInformation($view);
        }
    }

    /**
     * Assigns the device fingerprint id to the view in case the payment has fraud prevention enabled.
     *
     * @param \Enlight_View_Default $view
     * @param SessionManager        $sessionManager
     *
     * @throws \WirecardElasticEngine\Exception\UnknownPaymentException
     *
     * @since 1.0.0
     */
    private function assignDeviceFingerprint(\Enlight_View_Default $view, SessionManager $sessionManager)
    {
        $payment = $view->getAssign('sPayment');
        if (! isset($payment['name']) || ! $this->paymentFactory->isSupportedPayment($payment['name'])) {
            return;
        }

        $paymentMethod   = $this->paymentFactory->create($payment['name']);
        $fraudPrevention = $paymentMethod->getPaymentConfig()->hasFraudPrevention();
        if ($fraudPrevention) {
            $view->assign('wirecardElasticEngineIncludeDeviceFingerprintIFrame', true);
        }
        if ($fraudPrevention || $paymentMethod instanceof RatepayInvoicePayment) {
            $view->assign(
                'wirecardElasticEngineDeviceFingerprintId',
                $sessionManager->getDeviceFingerprintId($paymentMethod->getPaymentConfig()->getTransactionMAID())
            );
        }
    }

    /**
     * Some payments may require additional view assignments (e.g. SEPA input fields) on checkout confirm page.
     *
     * @param \Enlight_View_Default $view
     * @param SessionManager        $sessionManager
     *
     * @throws \WirecardElasticEngine\Exception\UnknownPaymentException
     *
     * @since 1.1.0 Added $sessionManager
     * @since 1.0.0
     */
    private function assignAdditionalViewAssignments(\Enlight_View_Default $view, SessionManager $sessionManager)
    {
        $payment = $view->getAssign('sPayment');
        if (! isset($payment['name']) || ! $this->paymentFactory->isSupportedPayment($payment['name'])) {
            return;
        }
        $paymentMethod = $this->paymentFactory->create($payment['name']);
        if ($paymentMethod instanceof AdditionalViewAssignmentsInterface) {
            $view->assign(
                'wirecardElasticEngineViewAssignments',
                $paymentMethod->getAdditionalViewAssignments($sessionManager)
            );
        }
    }
}
