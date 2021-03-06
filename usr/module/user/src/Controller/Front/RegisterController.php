<?php
/**
 * Pi Engine (http://pialog.org)
 *
 * @link            http://code.pialog.org for the Pi Engine source repository
 * @copyright       Copyright (c) Pi Engine http://pialog.org
 * @license         http://pialog.org/license.txt BSD 3-Clause License
 */

namespace Module\User\Controller\Front;

use Pi;
use Pi\Mvc\Controller\ActionController;
use Module\User\Form\ResendActivationForm;
use Module\User\Form\ResendActivationFilter;

/**
 * Register controller
 */
class RegisterController extends ActionController
{
    /**
     * Display register form
     *
     * @return array|void
     */
    public function indexAction()
    {
        if (!$this->checkAccess()) {
            return;
        }

        $result = array(
            'status'  => 0,
        );

        // Get register form
        $form = Pi::api('form', 'user')->loadForm('register');
        $form->setAttributes(array(
            'action' => $this->url('', array('action' => 'index')),
        ));

        // Handling register data
        if ($this->request->isPost()) {
            $post = $this->request->getPost();
            $form->loadInputFilter();
            $form->setData($post);
            if ($form->isValid()) {
                if ($this->config('require_register_complete')) {
                    $values = $form->getData();
                    $form = Pi::api('form', 'user')->loadForm('register-complete');
                    unset($values['submit']);
                    $form->setData($values);
                    $form->setAttributes(array(
                        'action' => $this->url('', array(
                                'action'     => 'complete',
                            )
                        ),
                    ));

                    $this->view()->assign(array(
                        'form'     => $form,
                        'complete' => 1
                    ));

                    return;
                } else {
                    // Complete register
                    $values = $form->getData();

                    $result = $this->completeRegister($values);
                    if (!empty($result['uid'])) {
                        $form = null;
                    }
                }
            }
            $this->view()->assign(array(
                'result'    => $result,
                'redirect'  => !empty($post['redirect']) ? urldecode($post['redirect']) : '',
            ));

        // Set redirect of register source
        } elseif ($form->get('redirect')) {
            $redirect = $this->params('redirect', $_SERVER['HTTP_REFERER']);
            $form->get('redirect')->setValue(rawurlencode($redirect));
        }

        // load admin language file
        Pi::service('i18n')->load(array('module/user', 'admin'));

        $this->view()->assign(array(
            'form'          => $form,
            'activation'    => $this->config('register_activation'),
        ));

        $this->view()->footScript()->prependFile($this->view()->assetModule('front/validator.js'));

        $this->view()->setTemplate('register-index');

        $this->view()->headTitle(__('Register'));
        $this->view()->headdescription(__('Account registration'), 'set');
        $this->view()->headkeywords($this->config('head_keywords'), 'set');
    }

    /**
     * User register complete form
     *
     * @return void|
     */
    public function completeAction()
    {
        if (!$this->checkAccess()) {
            return;
        }

        if (!$this->config('require_register_complete') ||
            !$this->request->isPost()
        ) {
            $this->redirect('', array(
                'controller'    => 'register',
                'action'        => 'index'
            ));
        }

        $result = array(
            'status' => 0,
        );
        $post = $this->request->getPost();
        $form = Pi::api('form', 'user')->loadForm('register-complete', true);
        $form->setAttributes(array(
            'action'    => $this->url('', array('action' => 'complete')),
        ));
        $form->setData($post);
        if ($form->isValid()) {
            $values = $form->getData();
            $result = $this->completeRegister($values);
            if (!empty($result['uid'])) {
                $form = null;
            }
        }

        $this->view()->assign(array(
            'result'        => $result,
            'complete'      => 1,
            'form'          => $form,
            'activation'    => $this->config('register_activation'),
        ));

        $this->view()->setTemplate('register-index');
    }

    /**
     * Activate user account
     */
    public function activateAction()
    {
        if (Pi::user()->getId()) {
            return $this->redirect(
                '',
                array(
                    'module'        => 'user',
                    'controller'    => 'dashboard',
                    'action'        => 'index'
                )
            );
        }

        $view = $this->view();
        $fallback = function ($message = '') use ($view) {
            $message = $message ?: __('Activation token is invalid.');
            $view->assign('result', array(
                'status'    => 0,
                'message'   => $message,
            ));
        };

        $hashUid = $this->params('uid', '');
        $token   = $this->params('token', '');
        // Check link params
        if (!$hashUid || !$token) {
            return $fallback();
        }

        // Search user data
        $userData = Pi::user()->data()->find(array(
            'module'    => 'user',
            'name'      => 'register_activation',
            'value'     => $token,
        ));
        if (!$userData) {
            return $fallback();
        }
        /*
        // Check expiration
        $expiration  = $userData['time'] + $this->config('activation_expiration') * 3600;
        if (time() > $expiration) {
            return $fallback(__('Activation link is expired.'));
        }
        */

        // Check uid
        $userRow = $this->getModel('account')->find($userData['uid']);
        if (!$userRow) {
            return $fallback();
        }
        $uid = (int) $userRow['id'];
        $data = array(
            'uid'       => $uid,
            'identity'  => $userRow['identity'],
        );
        if ($token != $this->createToken($data)) {
            return $fallback();
        }
        // Activate user
        $status = Pi::api('user', 'user')->activateUser($uid);

        // Check result
        if (!$status) {
            return $fallback();
        }

        // Delete user data
        Pi::user()->data()->delete(
            $userData['uid'],
            'register_activation'
        );

        // Target activate user event
        Pi::service('event')->trigger('user_activate', $uid);
        
        // Get redirect url
        $redirect = Pi::user()->data()->get($uid, 'register_redirect') ?: '';
        
        $result = array(
            'status'    => 1,
            'message'   => __('Account Activated successfully.'),
        );

        $this->view()->assign(array(
            'result'   => $result,
            'uid'      => $uid,
            'redirect' => $redirect,
        ));
    }

    /**
     * Reactivate user account
     *
     * @return array
     */
    public function reactivateAction()
    {
        $uid    = _get('uid');
        $result = array(
            'status'  => 0,
            'message' => __('Activation email sent failed.'),
        );

        if (!$uid) {
            return $result;
        }

        // Get user info
        $user = Pi::api('user', 'user')->get(
            $uid,
            array('id', 'identity', 'email', 'time_activated')
        );
        if (!$user || $user['time_activated']) {
            return $result;
        }

        // Check user data
        $userData = Pi::user()->data()->find(array(
            'uid'    => $uid,
            'module' => $this->getModule(),
            'name'   => 'register_activation'
        ));
        if (!$userData) {
            return $result;
        }

        $status = $this->sendNotification('activation', array(
            'email'     => $user['email'],
            'uid'       => $user['id'],
            'identity'  => $user['identity'],
        ));
        if ($status) {
            $result = array(
                'status'  => 1,
                'message' => __('Resend activation mail successfully.'),
            );
        }

        return $result;
    }

    /**
     * Re-send account activation email
     */
    public function resendActivationAction()
    {
        $form = new ResendActivationForm();
        $this->view()->setTemplate('register-resend-activation');

        if ($this->request->isPost()) {
            $post = $this->request->getPost();
            $form->setData($post);
            $form->setInputFilter(new ResendActivationFilter());

            if ($form->isValid()) {
                $values = $form->getData();

                // Check email
                $row = $this->getModel('account')->find($values['email'], 'email');
                if (!$row) {
                    $result = array(
                        'status'    => 0,
                        'message'   => __('Email was not found.'),
                    );
                } elseif ($row->time_activated) {
                    $result = array(
                        'status'    => 0,
                        'message'   => __('Account already activated.'),
                    );
                } else {
                    $status = $this->sendNotification('activation', array(
                        'email'     => $values['email'],
                        'uid'       => (int) $row['id'],
                        'identity'  => $row['identity'],
                    ));
                    if ($status) {
                        $result = array(
                            'status'  => 1,
                            'message' => __('Activation mail sent successfully.'),
                        );
                    } else {
                        $result = array(
                            'status'  => 0,
                            'message' => __('Activation mail was not sent.'),
                        );
                    }
                }

            } else {
                $result = array(
                    'status'    => 0,
                    'message'   => __('Invalid input.'),
                );
            }
            $this->view()->assign('result', $result);
            $this->view()->assign('form', $form);
        } else {
            $this->view()->assign('form', $form);
        }
    }

    /**
     * Profile complete action
     *
     * 1. Display profile complete form
     * 2. Save user information
     * 3. Sign user data
     */
    public function profileCompleteAction()
    {
        $result = array(
            'status' => 0,
        );

        $redirect = $this->params('redirect') ?: $this->url('' , array(
            array(
                'module'        => 'user',
                'controller'    => 'dashboard',
                'action'        => 'index'
            )
        ));

        if (!$this->config('require_profile_complete')) {
            return $this->redirect($redirect);
        }
        Pi::service('authentication')->requireLogin();
        $uid = Pi::user()->getId();

        $form = Pi::api('form', 'user')->loadForm('profile-complete');
        $form->setAttributes(array(
            'action'    => $this->url('', array('action' => 'profile.complete')),
        ));
        $form->get('redirect')->setValue($redirect);

        if ($this->request->isPost()) {
            $post = $this->request->getPost();
            $form->loadInputFilter();
            $form->setData($post);

            if ($form->isValid()) {
                $values = $form->getData();
                $redirect = $values['redirect'];
                unset($values['redirect']);
                $values['level'] = 1;
                $values['last_modified'] = time();
                Pi::api('user', 'user')->updateUser($uid, $values);

                return $this->redirect($redirect);
            } else {
                $this->view()->assign('result', $result);
            }
        }

        $this->view()->assign('form', $form);
        $this->view()->setTemplate('register-profile-complete');
    }

    /**
     * Activate an account
     *
     * @param int $uid
     *
     * @return bool
     */
    protected function activateUser($uid)
    {
        $status = Pi::api('user', 'user')->activateUser($uid);
        if ($status) {
            // Target activate user event
            Pi::service('event')->trigger('user_activate', $uid);
        }

        return $status;
    }

    /**
     * Complete user register
     *
     * @param array $values
     *
     * @return array
     */
    protected function completeRegister(array $values)
    {
        $result = array(
            'status'    => 0,
            'uid'       => 0,
            'message'   => '',
        );

        // Check email force set on register form
        if (!isset($values['email']) || empty($values['email'])) {
            $result['message'] = __('User information was not completed and user account was not saved.');
            return $result;
        }

        // Set email as identity if not set on register form
        if (!isset($values['identity']) || empty($values['identity'])) {
            $values['identity'] = $values['email'];
        }

        // Set name if not set on register form
        if (!isset($values['name']) || empty($values['name'])) {
            if (isset($values['first_name']) || isset($values['last_name'])) {
                $values['name'] = $values['first_name'] . ' ' . $values['last_name'];
            } else {
                $values['name'] = $values['identity'];
            }
        }

        // Set values
        $values['last_modified'] = time();
        $values['ip_register']   = Pi::user()->getIp();

        // Add user
        $uid = Pi::api('user', 'user')->addUser($values);
        if (!$uid || !is_int($uid)) {
            $result['message'] = __('User account was not saved.');
            return $result;
        }
        $result['uid'] = $uid;
        
        // Save url of register source page
        Pi::user()->data()->set(
            $uid,
            'register_redirect',
            urldecode($values['redirect']),
            $this->getModule()
        );

        // Set user role
        Pi::api('user', 'user')->setRole($uid, 'member');

        $status = 1;
        // Process activation
        $activationMode = $this->config('register_activation');
        // Automatically activated
        if ('auto' == $activationMode) {
            $status = $this->activateUser($uid);
            if (!$status) {
                $result['message'] = __('User account is registered successfully but activation was failed, please contact admin.');
            }
            if (Pi::user()->config('register_notification')) {
                $this->sendNotification('success', array(
                    'email'     => $values['email'],
                    'uid'       => $uid,
                    'identity'  => $values['identity'],
                    'name'      => $values['name'],
                    
                ));
            }
        // Activated by admin
        } elseif ('admin' == $activationMode) {
            if (Pi::user()->config('register_notification')) {
                $this->sendNotification('admin', array(
                    'email'     => $values['email'],
                    'uid'       => $uid,
                    'identity'  => $values['identity'],
                    'name'      => $values['name'],
                    
                ));
            }
        // Activated by email
        } elseif ('email' == $activationMode) {
            $status = $this->sendNotification('activation', array(
                'email'     => $values['email'],
                'uid'       => $uid,
                'identity'  => $values['identity'],
                'name'      => $values['name'],
                
            ));
            if (!$status) {
                $result['message'] = __('Account activation email was not able to send, please contact admin.');
            }
        }
        $result['status'] = $status;

        // Send notification email to admin
        if (Pi::user()->config('register_notification_admin')) {
            $this->sendNotificationToAdmin($activationMode, array(
                'email'     => $values['email'],
                'identity'  => $values['identity'],
                'name'      => $values['name'],
                'uid'       => $uid,
            ));
        }

        // Add subscription
        if (Pi::service('module')->isActive('subscription') && isset($values['newsletter']) && $values['newsletter'] == 1) {
            $peopleModel = Pi::model('people', 'subscription');
            $people = $peopleModel->createRow();

            $subscriptionValues = array();
            $subscriptionValues['campaign'] = 0;
            $subscriptionValues['uid'] = $uid;
            $subscriptionValues['status'] = 1;
            $subscriptionValues['time_join'] = time();
            $subscriptionValues['newsletter'] = 1;
            $subscriptionValues['email'] = null;
            $subscriptionValues['mobile'] = null;

            $people->assign($subscriptionValues);
            $people->save();

            $log = array(
                'uid' => $uid,
                'action' => 'subscribe_newsletter_register',
            );

            Pi::api('log', 'user')->add(null, null, $log);
        }

        // Get condition list
        $condition = Pi::api('condition', 'user')->getLastEligibleCondition();

        if($condition && isset($values['term']) && $values['term'] == 1){
            $log = array(
                'uid' => $uid,
                'data' => $condition->version,
                'action' => 'accept_conditions_register',
            );

            Pi::api('log', 'user')->add(null, null, $log);
        }

        return $result;
    }

    /**
     * Send notification email
     *
     * There are three types of activation:
     *  - (1) Automatically activated after registration
     *  - (2) Activated by an administrator
     *  - (3) Activated by email by the user himself
     *
     * For type (1), no email notification is to send.
     * For type (2), an email notification is to send after activation only if `register_notification` is enabled
     * For type (3), an email notification is to send.
     *
     * @param string $type
     * @param array $data   Data: email, uid, identity
     *
     * @return bool
     */
    protected function sendNotification($type, array $data)
    {
        $params = array();
        $template = '';
        switch ($type) {
            case 'success':
                $template = 'register-success-html';
                $redirect = Pi::user()->data()->get($data['uid'], 'register_redirect');
                $url = Pi::url(Pi::service('authentication')->getUrl('login', $redirect), true);
                $params = array(
                    'username'  => $data['name'],
                    'login_url' => $url,
                );
                break;
            case 'admin':
                $template = 'register-success-html';
                $params = array(
                    'username'  => $data['name'],
                );
                break;
            case 'activation':
                $token = $this->createToken($data);
                if ($token) {
                    $template = 'register-activation-html';
                    Pi::user()->data()->set(
                        $data['uid'],
                        'register_activation',
                        $token,
                        'user',
                        $this->config('activation_expiration') * 3600
                    );
                    $url = Pi::url($this->url('', array(
                        'action' => 'activate',
                        'uid'    => md5($data['uid']),
                        'token'  => $token
                    )), true);
                    $params = array(
                        'username'          => $data['name'],
                        'activation_url'    => $url,
                    );
                }
                break;
            default:
                break;
        }
        if (!$template) {
            return false;
        }

        // Load from HTML template
        $template   = Pi::service('mail')->template($template, $params);
        $subject    = $template['subject'];
        $body       = $template['body'];
        $type       = $template['format'];

        // Send email
        $message    = Pi::service('mail')->message($subject, $body, $type);
        $message->addTo($data['email']);
        $result     = Pi::service('mail')->send($message);

        return $result;
    }

    /**
     * Send notification email to website admin
     *
     * There are three types of activation:
     *  - (1) Automatically activated after registration
     *  - (2) Activated by an administrator
     *  - (3) Activated by email by the user himself
     *
     * For all types, an email notification is to send to admin after if `register_notification_admin` is enabled
     *
     * @param string $type
     * @param array $data   Data: email, uid, identity
     *
     * @return bool
     */
    protected function sendNotificationToAdmin($type, array $data)
    {
        $params = array();
        $template = '';
        switch ($type) {
            case 'auto':
                $template = 'admin-notification-register-auto';
                break;

            case 'email':
                $template = 'admin-notification-register-email';
                break;

            case 'approval':
                $template = 'admin-notification-register-approval';
                break;

            default:
                break;
        }

        $params = array(
            'identity'    => $data['identity'],
            'email'       => $data['email'],
            'name'        => $data['name'],
        );

        // Set admin mail
        $adminmail = Pi::config('adminmail');
        $adminname = Pi::config('adminname');
        $toAdmin = array(
            $adminmail => $adminname,
        );

        // Load from HTML template
        $template   = Pi::service('mail')->template($template, $params);
        $subject    = $template['subject'];
        $body       = $template['body'];
        $type       = $template['format'];

        // Send email
        $message    = Pi::service('mail')->message($subject, $body, $type);
        $message->addTo($toAdmin);
        $result     = Pi::service('mail')->send($message);

        return $result;
    }

    /**
     * Create user token
     *
     * @param array $data
     *
     * @return string
     */
    protected function createToken(array $data)
    {
        $token = '';
        if (!empty($data['uid']) && !empty($data['identity'])) {
            $token = md5($data['uid'] . $data['identity']);
        }

        return $token;
    }

    /**
     * Check access
     *
     * @return bool
     */
    protected function checkAccess()
    {
        // If disabled
        $registerDisable = $this->config('register_disable');
        if ($registerDisable) {
            $this->view()->setTemplate('register-disabled');
            return false;
        }

        if (Pi::service('user')->hasIdentity()) {
            $this->redirect()->toUrl($this->url('' , array(
                array(
                    'module'        => 'user',
                    'controller'    => 'dashboard',
                    'action'        => 'index'
                )
            )));
            return false;
        }

        return true;
    }

    public function validateInputAction(){
        Pi::service('log')->mute();

        $data = (array) $this->params()->fromQuery();

        $response = array(
            'error' => false,
            'message' => false
        );

        // Get register form
        /* @var $form \Module\User\Form\RegisterForm */
        $form = Pi::api('form', 'user')->loadForm('register');
        $form->loadInputFilter();
        $form->setData($data);

        if($form->has('captcha')){
            $form->remove('captcha');
        }

        $messages = array();

        if(!$form->isValid()){
            $messages = $form->getMessages();
        };


        $dataMessages = array_intersect_key($messages, $data);

        if($dataMessages){
            $firstElementMessages = array_shift($dataMessages);

            foreach($firstElementMessages as $message){

                $response['message'] = $message;
            }

            $response['error'] = true;

            $this->getResponse()->setStatusCode(404);
        }


        $this->getResponse()->getHeaders()->addHeaderLine('Content-Type', 'application/json');;
        $this->getResponse()->setContent(json_encode($response));

        return $this->getResponse();
    }
}
