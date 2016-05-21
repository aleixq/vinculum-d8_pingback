<?php

namespace Drupal\vinculum_pingback\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use \Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class VinculumSendSubscriber.
 *
 * @package Drupal\vinculum_pingback
 */
class VinculumSendSubscriber implements EventSubscriberInterface {

  /**
   * Agent.
   *
   * @const string
   */
  // User-agent to use when querying remote sites.
  const UA = 'Drupal Pingback (+http://drupal.org/project/vinculum)';

  /**
   * GuzzleHttp\Client definition.
   *
   * @var GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   * @param GuzzleHttp\Client $http_client
   *   GuzzleHttp\Client definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.

   */
  public function __construct(Client $http_client, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  static function getSubscribedEvents() {
    $events['vinculum_send'] = ['onVinculumSend'];

    return $events;
  }

  /**
   * This method is called whenever the vinculum_send event is
   * dispatched.
   *
   * @param GetResponseEvent $event
   */
  public function onVinculumSend(Event $event) {
    drupal_set_message('Event vinculum_send thrown by Subscriber in module vinculum_pingback.', 'status', TRUE);
    $this->sendPingback($event->getSourceUrl(), $event->getTargetUrl());
  }

  /**
   * Sends the pingback
   *
   * @param Url $sourceUrl
   * @param Url $targetUrl
   */
  public function sendPingback($sourceUrl, $targetUrl) {
    $source = $sourceUrl->setOption("absolute", TRUE)->toString();
    $target = $targetUrl->setOption("absolute", TRUE)->toString();
    if ($xmlrpc_endpoint = $this->getXmlRpcEndpoint($target)) {
      $params = array(
        '%source' => $source,
        '%target' => $target,
        '%endpoint' => $xmlrpc_endpoint,
      );
      $methods = array(
        'pingback.ping' => array($source, $target),
      );
      $result = xmlrpc($xmlrpc_endpoint, $methods, array('headers' => array('User-Agent' => self::UA )));
      if ($result) {
        $params = array(
          '%source' => $source,
          '%target' => $target,
        );
        return TRUE;
      }
      else {
        $params = array(
          '%source' => $source,
          '%target' => $target,
          '@errno' => xmlrpc_errno(),
          '@description' => xmlrpc_error_msg(),
        );
        $this->logger->error('Pingback to %target from %source failed.<br />Error @errno: @description', $params);
        return FALSE;
      }

    }
    // No XML-RPC endpoint detected; pingback failed.
    return FALSE;
  }
  /**
   * Get the URL of the XML-RPC endpoint that handles pingbacks for a URL.
   *
   * @param String $url
   * URL of the remote article
   *
   * @return String|FALSE
   * Absolute URL of the XML-RPC endpoint, or FALSE if pingback is not
   * supported.
   */
  protected function getXmlRpcEndpoint($url) {
    try {
      $response = $this->httpClient->get($url, array('headers' => array('Accept' => 'text/plain')));
      $data = $response->getBody(TRUE);
      $endpoint = $response->getHeader('X-Pingback');
      if ($endpoint){
        return $endpoint[0];
      }
      $crawler = new Crawler((string)$data);
      $endpoint = $crawler->filter('link[rel="pingback"]')->extract('href');
      if ($endpoint){
        return $endpoint[0];
      }   
    }
    catch (BadResponseException $exception) {
      $response = $exception->getResponse();
      $this->logger->notice('Failed to fetch url %endpoint due to HTTP error "%error"', array(
        '%endpoint' => $xmlrpc_endpoint, 
        '%error' => $response->getStatusCode() . ' ' . $response->getReasonPhrase()));
    }
    catch (RequestException $exception) {   
      $this->logger->notice('Failed to fetch url %endpoint due to error "%error"', array(
        '%endpoint' => $xmlrpc_endpoint, 
        '%error' => $exception->getMessage() ));
    }
    catch (InvalidArgumentException $exception){ 
      $this->logger->notice('Failed to fetch url %endpoint due to error "%error"', array(
        '%endpoint' => $xmlrpc_endpoint,
        '%error' => $exception->getMessage() ));
    }
    return FALSE;
  }
}
