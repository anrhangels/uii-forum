<?php
namespace uii\portal\event;
class errors implements \Symfony\Component\EventDispatcher\EventSubscriberInterface
{
    private $controller;
    public function __construct($controller) {$this->controller=$controller;}
    public static function getSubscribedEvents() {return ['kernel.exception'=>['missing',10]];}
    public function missing(\Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event)
    {
        if (!$event->getException() instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException || $event->getRequest()->isXmlHttpRequest()) {return;}
        $event->setResponse($this->controller->missing());
    }
}
