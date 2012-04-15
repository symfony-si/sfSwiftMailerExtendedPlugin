<?php

/*
 * This file is part of the symfony package.
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * sfMailerExtended is an extended class with multiple transports and fallbacks
 *
 * This class is instanciated by sfContext on demand.
 *
 * @package    sfSwiftMailerExtendedPlugin
 * @subpackage mailer
 * @author     Markus Welter <markus@welante.ch>
 * @version    SVN: $Id: sfMailerExtended.class.php 1 2011-01-19 17:30:00Z markus $
 */
class sfMailerExtended extends sfMailer
{
  protected
    $transports        = array(),
    $curTransport      = null,
    $maxRetries        = 3,
    $retries           = 0,
    $options           = array(),
    $default_transport = null,
    $dispatcher        = null;

  /**
   * Constructor.
   *
   * Available options:
   *
   *  * charset: The default charset to use for messages
   *  * logging: Whether to enable logging or not
   *  * delivery_strategy: The delivery strategy to use
   *  * spool_class: The spool class (for the spool strategy)
   *  * spool_arguments: The arguments to pass to the spool constructor
   *  * delivery_address: The email address to use for the single_address strategy
   *  * max_retries: How many times should the plugin try to send a mail! (Avoid endless loops!)
   *  * default_transport: If no transport given use this one instead
   *  * transport: The main transport configuration
   *  *   * class: The main transport class
   *  *   * param: The main transport parameters
   *
   * @param sfEventDispatcher $dispatcher An event dispatcher instance
   * @param array             $options    An array of options
   */
  public function __construct(sfEventDispatcher $dispatcher, $options)
  {
    // options
    $options = array_merge(array(
      'charset' => 'UTF-8',
      'logging' => false,
      'delivery_strategy' => 'realtime',
      'max_retries' => 3,
      'default_transport' => null,
      'transports' => array(),
    ), $options);

    // set given options
    $this->options = $options;
    // set dispatcher to reload transport configuration
    $this->dispatcher = $dispatcher;

    // we don't need transports for the class options
    unset($this->options['transports']);
    // we don't need transport in the local optoins
    unset($options['transport']);

    // set maximum retries
    $this->maxRetries = $options['max_retries'];

    if (count($options['transports']) == 0 )
    {
      throw new InvalidArgumentException(sprintf('At least one transport has to be defined'));
    }
    $this->transports = $options['transports'];

    if (!$options['default_transport'])
    {
      throw new InvalidArgumentException(sprintf('No default transport set (Please define one)'));
    }

    if ( !array_key_exists($options['default_transport'], $options['transports']) )
    {
      throw new InvalidArgumentException(sprintf('Unknown default transport "%s" (Please make sure the name matches one of the defined transports)', $options['default_transport']));
    }
    $this->default_transport = $options['default_transport'];

    // set delivery strategy
    $constantName = 'sfMailer::'.strtoupper($options['delivery_strategy']);
    $this->strategy = defined($constantName) ? constant($constantName) : false;
    if (!$this->strategy)
    {
      throw new InvalidArgumentException(sprintf('Unknown mail delivery strategy "%s" (should be one of realtime, spool, single_address, or none)', $options['delivery_strategy']));
    }
    $this->setup();
  }
  
  /**
   * setups swift mailer
   * 
   * @param string $transportName transport name, null is for default transport defined in factory.yml
   */
  public function setup($transportName = null)
  {
    if ( $transportName )
    {
      $this->curTransport = $transportName;
    }
    else
    {
      $this->curTransport = $this->default_transport;
    }

    $options = $this->options;

    $options['transport'] = $this->transports[$this->curTransport];
    
    parent::__construct($this->dispatcher, $options);
  }

  /**
   * Sends the given message.
   *
   * @param Swift_Transport $transport         A transport instance
   * @param string[]        &$failedRecipients An array of failures by-reference
   * @param string          $transportName     A transport name
   *
   * @return int|false The number of sent emails
   */
  public function send(Swift_Mime_Message $message, &$failedRecipients = null, $transportName = null)
  {
    // setup transport
    $transportName = ($transportName != null && !isset($this->transports[$transportName])) ? null : $transportName;
    if( $transportName || ( $this->retries != 0 && $transportName == null) )
    {
      $this->setup($transportName);
      $this->retries ++;
    }
    else
    {
      $this->retries = $this->maxRetries;
    }
    
    try
    {
      $ret = parent::send($message, $failedRecipients);
    }
    catch(Exception $e)
    {
      sfContext::getInstance()->getLogger()->info('Error sending mail with transport '.$transportName . ' attempt ' . $this->retries . ' of ' . $this->maxRetries);
      if($this->retries < $this->maxRetries)
      {
        $fallBack = $this->transports[$this->curTransport]['param']['fallback'];
        $ret = $this->send($message, $failedRecipients, $fallBack);
      }
      else
      {
        sfContext::getInstance()->getLogger()->info('Error sending mail after ' . $this->retries . ' retries');
        $this->retries = 0;
        throw new Exception('sfSwiftMailerExtendedPlugin error: ' . $e->getMessage());
      }
    }
    return $ret;
  }

  public function getCurTransport()
  {
    return $this->curTransport;
  }
}