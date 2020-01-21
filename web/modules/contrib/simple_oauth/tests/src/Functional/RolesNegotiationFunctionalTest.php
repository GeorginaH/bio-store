<?php

namespace Drupal\Tests\simple_oauth\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests for the roles negotiation.
 *
 * @group simple_oauth
 */
class RolesNegotiationFunctionalTest extends BrowserTestBase {

  use RequestHelperTrait;
  use SimpleOauthTestTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'serialization',
    'simple_oauth',
    'image',
    'text',
    'user',
  ];

  /**
   * The URL.
   *
   * @var \Drupal\Core\Url
   */
  protected $url;

  /**
   * The URL for the token test.
   *
   * @var \Drupal\Core\Url
   */
  protected $tokenTestUrl;

  /**
   * The client entity.
   *
   * @var \Drupal\consumers\Entity\Consumer
   */
  protected $client;

  /**
   * The user entity.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;


  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The client secret.
   *
   * @var string
   */
  protected $clientSecret;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->htmlOutputEnabled = FALSE;
    $this->tokenTestUrl = Url::fromRoute('oauth2_token.user_debug');
    $this->url = Url::fromRoute('oauth2_token.token');
    $this->user = $this->drupalCreateUser();
    // Set up a HTTP client that accepts relative URLs.
    $this->httpClient = $this->container->get('http_client_factory')
      ->fromOptions(['base_uri' => $this->baseUrl]);
    $this->clientSecret = $this->getRandomGenerator()->string();
    // Create a role 'foo' and add two permissions to it.
    $role = Role::create([
      'id' => 'foo',
      'label' => 'Foo',
      'is_admin' => FALSE,
    ]);
    $this->grantPermissions(
      Role::load(RoleInterface::ANONYMOUS_ID),
      ['debug simple_oauth tokens']
    );
    $this->grantPermissions(
      Role::load(RoleInterface::AUTHENTICATED_ID),
      ['debug simple_oauth tokens']
    );
    $role->grantPermission('view own simple_oauth entities');
    $role->save();
    $role = Role::create([
      'id' => 'bar',
      'label' => 'Bar',
      'is_admin' => FALSE,
    ]);
    $role->grantPermission('administer simple_oauth entities');
    $role->save();
    $role = Role::create([
      'id' => 'oof',
      'label' => 'Oof',
      'is_admin' => FALSE,
    ]);
    $role->grantPermission('delete own simple_oauth entities');
    $role->save();
    $this->user->addRole('foo');
    $this->user->addRole('bar');
    $this->user->save();

    // Create a Consumer.
    $this->client = Consumer::create([
      'owner_id' => 1,
      'user_id' => $this->user->id(),
      'label' => $this->getRandomGenerator()->name(),
      'secret' => $this->clientSecret,
      'confidential' => TRUE,
      'roles' => [['target_id' => 'oof']],
    ]);
    $this->client->save();

    $this->setUpKeys();
  }

  /**
   * Test access to own published node with missing role on User entity.
   */
  public function testRequestWithRoleRemovedFromUser() {
    $access_token = $this->getAccessToken(['foo', 'bar']);

    // Get detailed information about the authenticated user.
    $response = $this->get(
      $this->tokenTestUrl,
      [
        'query' => ['_format' => 'json'],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]
    );
    $parsed_response = Json::decode((string) $response->getBody());
    $this->assertEquals($this->user->id(), $parsed_response['id']);
    $this->assertEquals(['foo', 'bar', 'authenticated', 'oof'], $parsed_response['roles']);
    $this->assertTrue($parsed_response['permissions']['view own simple_oauth entities']['access']);
    $this->assertTrue($parsed_response['permissions']['administer simple_oauth entities']['access']);

    $this->user->removeRole('bar');
    $this->user->save();

    // We have edited the user, but there was a non-expired existing token for
    // that user. Even though the TokenUser has the roles assigned, the
    // underlying user doesn't, so access should not be granted.
    $response = $this->get(
      $this->tokenTestUrl,
      [
        'query' => ['_format' => 'json'],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]
    );
    $parsed_response = Json::decode((string) $response->getBody());
    // The token was successfully removed. The negotiated user is the anonymous
    // user.
    $this->assertEquals(0, $parsed_response['id']);
    $this->assertEquals(['anonymous'], $parsed_response['roles']);
    $this->assertFalse($parsed_response['permissions']['view own simple_oauth entities']['access']);
    $this->assertFalse($parsed_response['permissions']['administer simple_oauth entities']['access']);

    // Request the access token again. This time the user doesn't have the role
    // requested at the time of generating the token.
    $access_token = $this->getAccessToken(['foo', 'bar']);
    $response = $this->get(
      $this->tokenTestUrl,
      [
        'query' => ['_format' => 'json'],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]
    );
    $parsed_response = Json::decode((string) $response->getBody());
    // The negotiated user is the expected user.
    $this->assertEquals($this->user->id(), $parsed_response['id']);
    $this->assertEquals(['foo', 'authenticated', 'oof'], $parsed_response['roles']);
    $this->assertTrue($parsed_response['permissions']['view own simple_oauth entities']['access']);
    $this->assertFalse($parsed_response['permissions']['administer simple_oauth entities']['access']);
  }

  /**
   * Test access to own unpublished node but with the role removed from client.
   */
  public function testRequestWithRoleRemovedFromClient() {
    $access_token = $this->getAccessToken(['oof']);

    // Get detailed information about the authenticated user.
    $response = $this->get(
      $this->tokenTestUrl,
      [
        'query' => ['_format' => 'json'],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]
    );
    $parsed_response = Json::decode((string) $response->getBody());
    $this->assertEquals($this->user->id(), $parsed_response['id']);
    $this->assertEquals(['authenticated', 'oof'], $parsed_response['roles']);
    $this->assertTrue($parsed_response['permissions']['delete own simple_oauth entities']['access']);

    $this->client->set('roles', []);
    // After saving the client entity, the token should be deleted.
    $this->client->save();

    // User should NOT have access to view own simple_oauth entities,
    // because the scope is indicated in the token request, but
    // missing from the client content entity.
    $response = $this->get(
      $this->tokenTestUrl,
      [
        'query' => ['_format' => 'json'],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]
    );
    $parsed_response = Json::decode((string) $response->getBody());
    // The token was successfully removed. The negotiated user is the anonymous
    // user.
    $this->assertEquals(0, $parsed_response['id']);
    $this->assertEquals(['anonymous'], $parsed_response['roles']);
    $this->assertFalse($parsed_response['permissions']['view own simple_oauth entities']['access']);

    $access_token = $this->getAccessToken(['oof']);
    // Get detailed information about the authenticated user.
    $response = $this->get(
      $this->tokenTestUrl,
      [
        'query' => ['_format' => 'json'],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]
    );
    $parsed_response = Json::decode((string) $response->getBody());
    $this->assertEquals($this->user->id(), $parsed_response['id']);
    $this->assertEquals(['authenticated'], $parsed_response['roles']);
    $this->assertFalse($parsed_response['permissions']['delete own simple_oauth entities']['access']);
  }

  /**
   * Test access to own unpublished node but with missing scope.
   */
  public function testRequestWithMissingScope() {
    $access_token = $this->getAccessToken();

    $response = $this->get(
      $this->tokenTestUrl,
      [
        'query' => ['_format' => 'json'],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]
    );
    $parsed_response = Json::decode((string) $response->getBody());
    $this->assertEquals($this->user->id(), $parsed_response['id']);
    $this->assertEquals(['authenticated', 'oof'], $parsed_response['roles']);
    $this->assertFalse($parsed_response['permissions']['view own simple_oauth entities']['access']);
  }

  /**
   * Return an access token.
   *
   * @param array $scopes
   *   The scopes.
   *
   * @return string
   *   The access token.
   */
  private function getAccessToken(array $scopes = []) {
    $valid_payload = [
      'grant_type' => 'client_credentials',
      'client_id' => $this->client->uuid(),
      'client_secret' => $this->clientSecret,
    ];
    if (!empty($scopes)) {
      $valid_payload['scope'] = implode(' ', $scopes);
    }
    $response = $this->post($this->url, $valid_payload);
    $parsed_response = Json::decode((string) $response->getBody());

    return isset($parsed_response['access_token'])
      ? $parsed_response['access_token']
      : NULL;
  }

}
