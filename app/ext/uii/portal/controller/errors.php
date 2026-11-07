<?php
namespace uii\portal\controller;
class errors
{
    private $helper;private $template;private $user;
    public function __construct($helper,$template,$user) {$this->helper=$helper;$this->template=$template;$this->user=$user;}
    public function missing()
    {
        $this->user->update_session_page=false;
        $registered=!empty($this->user->data['is_registered']);
        $this->template->assign_vars(['ERROR_HOME'=>generate_board_url().'/','ERROR_LOGIN'=>append_sid(generate_board_url().'/ucp.php','mode=login'),'ERROR_REGISTERED'=>$registered]);
        $response=$this->helper->render('@uii_portal/not_found.html','Страница не найдена',404);
        $response->headers->set('Cache-Control','private, no-store');$response->headers->set('X-Robots-Tag','noindex');return $response;
    }
}
