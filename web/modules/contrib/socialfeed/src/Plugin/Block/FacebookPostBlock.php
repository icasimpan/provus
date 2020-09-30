<?php

namespace Drupal\socialfeed\Plugin\Block;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\socialfeed\Services\FacebookPostCollectorFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'FacebookPostBlock' block.
 *
 * @Block(
 *  id = "facebook_post",
 *  admin_label = @Translation("Facebook Block"),
 * )
 */
class FacebookPostBlock extends SocialBlockBase implements ContainerFactoryPluginInterface, BlockPluginInterface {

  /**
   * Facebook service.
   *
   * @var \Drupal\socialfeed\Services\FacebookPostCollectorFactory
   */
  protected $facebook;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * AccountInterface.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private $logger;

  /**
   * FacebookPostBlock constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\socialfeed\Services\FacebookPostCollectorFactory $facebook
   *   The ConfigFactory $config_factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The InstagramPostCollector $instagram.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The currently logged in user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FacebookPostCollectorFactory $facebook, ConfigFactoryInterface $config, AccountInterface $currentUser, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->facebook = $facebook;
    $this->config = $config->get('socialfeed.facebooksettings');
    $this->currentUser = $currentUser;
    $this->logger = $logger_factory->get('socialfeed');
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Symfony\Component\DependencyInjection\ContainerInterface parameter.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin implementation definition.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('socialfeed.facebook'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $post_type_options = ['permalink_url', 'status', 'photo', 'video'];

    $form['overrides']['page_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook Page Name'),
      '#default_value' => $this->defaultSettingValue('page_name'),
      '#description' => $this->t('eg. If your Facebook page URL is this @facebook, then use YOUR_PAGE_NAME above.', ['@facebook' => 'http://www.facebook.com/YOUR_PAGE_NAME']),
      '#size' => 60,
      '#maxlength' => 100,
      '#required' => TRUE,
    ];

    $form['overrides']['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook App ID'),
      '#default_value' => $this->defaultSettingValue('app_id'),
      '#size' => 60,
      '#maxlength' => 100,
      '#required' => TRUE,
    ];

    $form['overrides']['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook Secret Key'),
      '#default_value' => $this->defaultSettingValue('secret_key'),
      '#size' => 60,
      '#maxlength' => 100,
      '#required' => TRUE,
    ];

    $form['overrides']['user_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook User Token'),
      '#default_value' => $this->defaultSettingValue('user_token'),
      '#description' => $this->t('This is available at @facebook', ['@facebook' => 'https://developers.facebook.com/tools/explorer/']),
      '#size' => 60,
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['overrides']['user_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facebook User Token'),
      '#default_value' => $this->defaultSettingValue('user_token'),
      '#size' => 60,
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['overrides']['no_feeds'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Feeds'),
      '#default_value' => $this->defaultSettingValue('no_feeds'),
      '#size' => 60,
      '#maxlength' => 60,
      '#max' => 100,
      '#min' => 1,
    ];

    $form['overrides']['all_types'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show all post types'),
      '#default_value' => $this->defaultSettingValue('all_types'),
      '#states' => [
        'required' => [],
      ],
    ];

    $form['overrides']['post_type'] = [
      '#type' => 'select',
      '#title' => 'Select your post type(s) to show',
      '#default_value' => $this->defaultSettingValue('post_type'),
      '#options' => array_combine($post_type_options, $post_type_options),
      '#empty_option' => $this->t('- Select -'),
      '#states' => [
        'visible' => [
          ':input[name="settings[overrides][all_types]"]' => ['checked' => FALSE],
        ],
        'required' => [
          ':input[name="settings[overrides][all_types]"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $this->blockFormElementStates($form);
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @return array
   *   Returning data as an array.
   *
   * @throws \Facebook\Exceptions\FacebookSDKException
   */
  public function build() {
    $build = [];
    $items = [];
    $block_settings = $this->getConfiguration();
    try {
      if ($block_settings['override']) {
        $facebook = $this->facebook->createInstance($block_settings['app_id'], $block_settings['secret_key'], $block_settings['user_token'], $this->config->get('page_name'));
      }
      else {
        $facebook = $this->facebook->createInstance($this->config->get('app_id'), $this->config->get('secret_key'), $this->config->get('user_token'), $this->config->get('page_name'));
      }

      $post_types = $this->getSetting('all_types');
      if (!$post_types) {
        $post_types = $this->getSetting('post_type');
      }
      $posts = $facebook->getPosts(
        $this->getSetting('page_name'),
        $post_types,
        $this->getSetting('no_feeds')
      );
      foreach ($posts as $post) {
        if ($post['status_type'] = !NULL) {
          $items[] = [
            '#theme' => [
              'socialfeed_facebook_post__' . $post['status_type'],
              'socialfeed_facebook_post',
            ],
            '#post' => $post,
            '#cache' => [
              // Cache for 1 hour.
              'max-age' => 60 * 60,
              'cache tags' => $this->config->getCacheTags(),
              'context' => $this->config->getCacheContexts(),
            ],
          ];
        }
      }
    } catch (Exception $exception) {
      $this->logger->error($this->t('Exception: @exception', [
        '@exception' => $exception->getMessage(),
      ]));
    }

    $build['posts'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];

    return $build;
  }

}
