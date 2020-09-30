<?php

namespace Drupal\socialfeed\Plugin\Block;

use Drupal\Core\Block\Annotation\Block;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\socialfeed\Services\InstagramPostCollectorFactory;
use EspressoDev\InstagramBasicDisplay\InstagramBasicDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a 'InstagramPostBlock' block.
 *
 * @Block(
 *  id = "instagram_post_block",
 *  admin_label = @Translation("Instagram Block"),
 * )
 */
class InstagramPostBlock extends SocialBlockBase implements ContainerFactoryPluginInterface, BlockPluginInterface {

  /**
   * Drupal\Core\Config\ConfigFactory definition.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $config;

  /**
   * Instagram Service.
   *
   * @var \Drupal\socialfeed\Services\InstagramPostCollectorFactory
   */
  protected $instagram;

  /**
   * AccountInterface.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactory $config_factory, InstagramPostCollectorFactory $instagram, AccountInterface $currentUser, Request $request) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config_factory->getEditable('socialfeed.instagramsettings');
    $this->instagram = $instagram;
    $this->currentUser = $currentUser;
    $this->currentRequest = $request;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('socialfeed.instagram'),
      $container->get('current_user'),
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $this->messenger()->addWarning($this->t('By overriding the `FEED CONFIGURATION` settings here, this block won\'t receive the renewed <strong>Access Token</strong> when the current one expires in <strong>60 days</strong>, hence you have to manually add a new <strong>Access Token</strong> post expiry. <br /> Global Settings doesn\'t have this limitation so in case if you haven\'t configured them here yet, then you should configure the `FEED CONFIGURATION` at <a href="@admin">/admin/config/socialfeed/instagram</a>',
      ['@admin' => Url::fromRoute('socialfeed.instagram_settings_form')->toString()])
    );

    $form['overrides']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App ID'),
      '#description' => $this->t('App ID from Instagram account'),
      '#default_value' => $this->defaultSettingValue('client_id'),
      '#size' => 60,
      '#maxlength' => 100,
      '#required' => TRUE,
    ];

    $form['overrides']['app_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App Secret'),
      '#description' => $this->t('App Secret from Instagram account'),
      '#default_value' => $this->defaultSettingValue('app_secret'),
      '#size' => 60,
      '#maxlength' => 100,
      '#required' => TRUE,
    ];

    $form['overrides']['redirect_uri'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Redirect URI'),
      '#description' => $this->t('Redirect Uri added to Instagram account'),
      '#default_value' => $this->defaultSettingValue('redirect_uri'),
      '#size' => 60,
      '#maxlength' => 100,
      '#required' => TRUE,
    ];

    $form['overrides']['access_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access Token'),
      '#description' => $this->t('This access token will need to be renewed every 60 days in order to continue working. You can create an access token through the <a href="https://developers.facebook.com/docs/instagram-basic-display-api/overview#user-token-generator" target="_blank">Token Generator</a>'),
      '#default_value' => $this->defaultSettingValue('access_token'),
      '#size' => 60,
      '#maxlength' => 300,
      '#required' => TRUE,
    ];

    $form['overrides']['picture_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Picture Count'),
      '#default_value' => $this->defaultSettingValue('picture_count'),
      '#size' => 60,
      '#maxlength' => 100,
      '#min' => 1,
    ];

    $this->blockFormElementStates($form);

    $form['overrides']['post_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show post URL'),
      '#default_value' => $this->defaultSettingValue('post_link'),
    ];

    $form['overrides']['video_thumbnail'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show video thumbnails instead of actual videos'),
      '#default_value' => $this->defaultSettingValue('video_thumbnail'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $items = [];

    // Refresh the long-lived Access Token.
    $this->refreshAccessToken();

    $instagram = $this->instagram->createInstance($this->getSetting('client_id'), $this->getSetting('app_secret'), $this->getSetting('redirect_uri'), $this->getSetting('access_token'));

    $posts = $instagram->getPosts(
      $this->getSetting('picture_count')
    );

    // Validating the settings.
    $post_link = $this->getSetting('post_link');
    $video_thumbnail = $this->getSetting('video_thumbnail');

    foreach ($posts as $post) {
      $theme_type = ($post['raw']->media_type == 'VIDEO') ? 'video' : ($post['raw']->media_type == 'CAROUSEL_ALBUM' ? 'carousel_album' : 'image');

      // Set the post link.
      if ($post_link) {
        $post['post_url'] = $post['raw']->permalink;
      }

      // Use video thumbnails instead of rendered videos.
      if ($video_thumbnail && $theme_type == 'video') {
        $theme_type = 'image';
        $post['media_url'] = $post['raw']->thumbnail_url;
      }

      $items[] = [
        '#theme' => 'socialfeed_instagram_post_' . $theme_type,
        '#post' => $post,
        '#cache' => [
          // Cache for 1 hour.
          'max-age' => 60 * 60,
          'cache tags' => $this->config->getCacheTags(),
          'context' => $this->config->getCacheContexts(),
        ],
      ];
    }
    $build['posts'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];
    return $build;
  }

  /**
   * Update the access token with a "long-lived" one.
   *
   * @throws \EspressoDev\InstagramBasicDisplay\InstagramBasicDisplayException
   */
  protected function refreshAccessToken() {
    $config = $this->config;

    // 50 Days.
    $days_later = 50 * 24 * 60 * 60;

    // Exit if the token doesn't need updating.
    if (empty($config->get('access_token_date')) || ($config->get('access_token_date') + $days_later) > time()) {
      return;
    }

    // Update the token.
    $instagram = new InstagramBasicDisplay([
      'appId' => $config->get('client_id'),
      'appSecret' => $config->get('app_secret'),
      'redirectUri' => $this->currentRequest->getSchemeAndHttpHost() . Url::fromRoute('socialfeed.instagram_auth')->toString(),
    ]);

    // Refresh this token.
    $token = $instagram->refreshToken($config->get('access_token'), TRUE);

    if ($token) {
      $config->set('access_token', $token);
      $config->set('access_token_date', time());
      $config->save();
    }
  }

}
