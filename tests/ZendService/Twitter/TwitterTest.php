<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Service
 */

namespace ZendTest\Twitter;

use Zend\Http;
use ZendService\Twitter;
use ZendService\Twitter\Response as TwitterResponse;
use Zend\Http\Client\Adapter\Curl as CurlAdapter;

/**
 * @category   Zend
 * @package    Zend_Service_Twitter
 * @subpackage UnitTests
 * @group      Zend_Service
 * @group      Zend_Service_Twitter
 */
class TwitterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Quick reusable Twitter Service stub setup. Its purpose is to fake
     * interactions with Twitter so the component can focus on what matters:
     * 1. Makes correct requests (URI, parameters and HTTP method)
     * 2. Parses all responses and returns a TwitterResponse
     * 3. TODO: Correctly utilises all optional parameters
     *
     * If used correctly, tests will be fast, efficient, and focused on
     * Zend_Service_Twitter's behaviour only. No other dependencies need be
     * tested. The Twitter API Changelog should be regularly reviewed to
     * ensure the component is synchronised to the API.
     *
     * @param string $path Path appended to Twitter API endpoint
     * @param string $method Do we expect HTTP GET or POST?
     * @param string $responseFile File containing a valid XML response to the request
     * @param array $params Expected GET/POST parameters for the request
     * @return \Zend\Http\Client
     */
    protected function stubTwitter($path, $method, $responseFile = null, array $params = null)
    {
        $client = $this->getMock('ZendOAuth\Client', array(), array(), '', false);
        $client->expects($this->any())->method('resetParameters')
            ->will($this->returnValue($client));
        $client->expects($this->once())->method('setUri')
            ->with('https://api.twitter.com/1.1/' . $path);
        $response = $this->getMock('Zend\Http\Response', array(), array(), '', false);
        if (!is_null($params)) {
            $setter = 'setParameter' . ucfirst(strtolower($method));
            $client->expects($this->once())->method($setter)->with($params);
        }
        $client->expects($this->once())->method('send')->with()
            ->will($this->returnValue($response));
        $response->expects($this->any())->method('getBody')
            ->will($this->returnValue(
                isset($responseFile) ? file_get_contents(__DIR__ . '/_files/' . $responseFile) : ''
            ));
        return $client;
    }

    /**
     * OAuth tests
     */

    public function testProvidingAccessTokenInOptionsSetsHttpClientFromAccessToken()
    {
        $token = $this->getMock('ZendOAuth\Token\Access', array(), array(), '', false);
        $client = $this->getMock('ZendOAuth\Client', array(), array(), '', false);
        $token->expects($this->once())->method('getHttpClient')
            ->with(array('token'=>$token, 'siteUrl'=>'https://api.twitter.com/oauth'))
            ->will($this->returnValue($client));

        $twitter = new Twitter\Twitter(array('accessToken'=>$token, 'opt1'=>'val1'));
        $this->assertTrue($client === $twitter->getHttpClient());
    }

    public function testNotAuthorisedWithoutToken()
    {
        $twitter = new Twitter\Twitter;
        $this->assertFalse($twitter->isAuthorised());
    }

    public function testChecksAuthenticatedStateBasedOnAvailabilityOfAccessTokenBasedClient()
    {
        $token = $this->getMock('ZendOAuth\Token\Access', array(), array(), '', false);
        $client = $this->getMock('ZendOAuth\Client', array(), array(), '', false);
        $token->expects($this->once())->method('getHttpClient')
            ->with(array('token'=>$token, 'siteUrl'=>'https://api.twitter.com/oauth'))
            ->will($this->returnValue($client));

        $twitter = new Twitter\Twitter(array('accessToken'=>$token));
        $this->assertTrue($twitter->isAuthorised());
    }

    public function testRelaysMethodsToInternalOAuthInstance()
    {
        $oauth = $this->getMock('ZendOAuth\Consumer', array(), array(), '', false);
        $oauth->expects($this->once())->method('getRequestToken')->will($this->returnValue('foo'));
        $oauth->expects($this->once())->method('getRedirectUrl')->will($this->returnValue('foo'));
        $oauth->expects($this->once())->method('redirect')->will($this->returnValue('foo'));
        $oauth->expects($this->once())->method('getAccessToken')->will($this->returnValue('foo'));
        $oauth->expects($this->once())->method('getToken')->will($this->returnValue('foo'));

        $twitter = new Twitter\Twitter(array('opt1'=>'val1'), $oauth);
        $this->assertEquals('foo', $twitter->getRequestToken());
        $this->assertEquals('foo', $twitter->getRedirectUrl());
        $this->assertEquals('foo', $twitter->redirect());
        $this->assertEquals('foo', $twitter->getAccessToken(array(), $this->getMock('ZendOAuth\Token\Request')));
        $this->assertEquals('foo', $twitter->getToken());
    }

    public function testResetsHttpClientOnReceiptOfAccessTokenToOauthClient()
    {
        $this->markTestIncomplete('Problem with resolving classes for mocking');
        $oauth = $this->getMock('ZendOAuth\Consumer', array(), array(), '', false);
        $client = $this->getMock('ZendOAuth\Client', array(), array(), '', false);
        $token = $this->getMock('ZendOAuth\Token\Access', array(), array(), '', false);
        $token->expects($this->once())->method('getHttpClient')->will($this->returnValue($client));
        $oauth->expects($this->once())->method('getAccessToken')->will($this->returnValue($token));
        $client->expects($this->once())->method('setHeaders')->with('Accept-Charset', 'ISO-8859-1,utf-8');

        $twitter = new Twitter\Twitter(array(), $oauth);
        $twitter->getAccessToken(array(), $this->getMock('ZendOAuth\Token\Request'));
        $this->assertTrue($client === $twitter->getHttpClient());
    }

    public function testAuthorisationFailureWithUsernameAndNoAccessToken()
    {
        $this->setExpectedException('ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter(array('username'=>'me'));
        $twitter->statusesPublicTimeline();
    }

    /**
     * @group ZF-8218
     */
    public function testUserNameNotRequired()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubTwitter(
            'users/show.json', Http\Request::METHOD_GET, 'users.show.mwop.json',
            array('screen_name' => 'mwop')
        ));
        $response = $twitter->users->show('mwop');
        $this->assertInstanceOf('ZendService\Twitter\Response', $response);
        $exists = $response->id !== null;
        $this->assertTrue($exists);
    }

    /**
     * @group ZF-7781
     */
    public function testRetrievingStatusesWithValidScreenNameThrowsNoInvalidScreenNameException()
    {
        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/user_timeline.json', Http\Request::METHOD_GET, 'statuses.user_timeline.mwop.json'
        ));
        $twitter->statuses->userTimeline(array('screen_name' => 'mwop'));
    }

    /**
     * @group ZF-7781
     */
    public function testRetrievingStatusesWithInvalidScreenNameCharacterThrowsInvalidScreenNameException()
    {
        $this->setExpectedException('ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter();
        $twitter->statuses->userTimeline(array('screen_name' => 'abc.def'));
    }

    /**
     * @group ZF-7781
     */
    public function testRetrievingStatusesWithInvalidScreenNameLengthThrowsInvalidScreenNameException()
    {
        $this->setExpectedException('\ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter();
        $twitter->statuses->userTimeline(array('screen_name' => 'abcdef_abc123_abc123x'));
    }

    /**
     * @group ZF-7781
     */
    public function testStatusUserTimelineConstructsExpectedGetUriAndOmitsInvalidParams()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/user_timeline.json', Http\Request::METHOD_GET, 'statuses.user_timeline.mwop.json', array(
                'count' => '123',
                'user_id' => 783214,
                'since_id' => '10000',
                'max_id' => '20000',
                'screen_name' => 'twitter'
            )
        ));
        $twitter->statuses->userTimeline(array(
            'id' => '783214',
            'since' => '+2 days', /* invalid param since Apr 2009 */
            'page' => '1',
            'count' => '123',
            'user_id' => '783214',
            'since_id' => '10000',
            'max_id' => '20000',
            'screen_name' => 'twitter'
        ));
    }

    public function testOverloadingGetShouldReturnObjectInstanceWithValidMethodType()
    {
        $twitter = new Twitter\Twitter;
        $return = $twitter->statuses;
        $this->assertSame($twitter, $return);
    }

    public function testOverloadingGetShouldthrowExceptionWithInvalidMethodType()
    {
        $this->setExpectedException('ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter;
        $return = $twitter->foo;
    }

    public function testOverloadingGetShouldthrowExceptionWithInvalidFunction()
    {
        $this->setExpectedException('ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter;
        $return = $twitter->foo();
    }

    public function testMethodProxyingDoesNotThrowExceptionsWithValidMethods()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/sample.json', Http\Request::METHOD_GET, 'statuses.sample.json'
        ));
        $twitter->statuses->sample();
    }

    public function testMethodProxyingThrowExceptionsWithInvalidMethods()
    {
        $this->setExpectedException('ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter;
        $twitter->statuses->foo();
    }

    public function testVerifiedCredentials()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'account/verify_credentials.json', Http\Request::METHOD_GET, 'account.verify_credentials.json'
        ));
        $response = $twitter->account->verifyCredentials();
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testSampleTimelineStatusReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/sample.json', Http\Request::METHOD_GET, 'statuses.sample.json'
        ));
        $response = $twitter->statuses->sample();
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testRateLimitStatusReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'application/rate_limit_status.json', Http\Request::METHOD_GET, 'application.rate_limit_status.json'
        ));
        $response = $twitter->application->rateLimitStatus();
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testRateLimitStatusHasHitsLeft()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'application/rate_limit_status.json', Http\Request::METHOD_GET, 'application.rate_limit_status.json'
        ));
        $response = $twitter->application->rateLimitStatus();
        $status = $response->toValue();
        $this->assertEquals(180, $status->resources->statuses->{'/statuses/user_timeline'}->remaining);
    }

    /**
     * TODO: Check actual purpose. New friend returns XML response, existing
     * friend returns a 403 code.
     */
    public function testFriendshipCreate()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'friendships/create.json', Http\Request::METHOD_POST, 'friendships.create.twitter.json',
            array('screen_name' => 'twitter')
        ));
        $response = $twitter->friendships->create('twitter');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testHomeTimelineWithCountReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/home_timeline.json', Http\Request::METHOD_GET, 'statuses.home_timeline.page.json',
            array('count' => 3)
        ));
        $response = $twitter->statuses->homeTimeline(array('count' => 3));
        $this->assertTrue($response instanceof TwitterResponse);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     */
    public function testUserTimelineReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/user_timeline.json', Http\Request::METHOD_GET, 'statuses.user_timeline.mwop.json',
            array('screen_name' => 'mwop')
        ));
        $response = $twitter->statuses->userTimeline(array('screen_name' => 'mwop'));
        $this->assertTrue($response instanceof TwitterResponse);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     */
    public function testPostStatusUpdateReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/update.json', Http\Request::METHOD_POST, 'statuses.update.json',
            array('status'=>'Test Message 1')
        ));
        $response = $twitter->statuses->update('Test Message 1');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testPostStatusUpdateToLongShouldThrowException()
    {
        $this->setExpectedException('ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter;
        $twitter->statuses->update('Test Message - ' . str_repeat(' Hello ', 140));
    }

    public function testPostStatusUpdateEmptyShouldThrowException()
    {
        $this->setExpectedException('ZendService\Twitter\Exception\ExceptionInterface');
        $twitter = new Twitter\Twitter;
        $twitter->statuses->update('');
    }

    public function testShowStatusReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/show/307529814640840705.json', Http\Request::METHOD_GET, 'statuses.show.json'
        ));
        $response = $twitter->statuses->show('307529814640840705');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testCreateFavoriteStatusReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'favorites/create.json', Http\Request::METHOD_POST, 'favorites.create.json',
            array('id' => 15042159587)
        ));
        $response = $twitter->favorites->create(15042159587);
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testFavoritesListReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'favorites/list.json', Http\Request::METHOD_GET, 'favorites.list.json'
        ));
        $response = $twitter->favorites->list();
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testDestroyFavoriteReturnsResponse()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'favorites/destroy.json', Http\Request::METHOD_POST, 'favorites.destroy.json',
            array('id' => 15042159587)
        ));
        $response = $twitter->favorites->destroy(15042159587);
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testStatusDestroyReturnsResult()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/destroy/15042159587.json', Http\Request::METHOD_POST, 'statuses.destroy.json'
        ));
        $response = $twitter->statuses->destroy(15042159587);
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testStatusHomeTimelineWithNoOptionsReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/home_timeline.json', Http\Request::METHOD_GET, 'statuses.home_timeline.page.json'
        ));
        $response = $twitter->statuses->homeTimeline();
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testUserShowByIdReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'users/show.json', Http\Request::METHOD_GET, 'users.show.mwop.json',
            array('screen_name' => 'mwop')
        ));
        $response = $twitter->users->show('mwop');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     * @todo rename to "mentions_timeline"
     */
    public function testStatusMentionsReturnsResults()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'statuses/mentions_timeline.json', Http\Request::METHOD_GET, 'statuses.mentions_timeline.json'
        ));
        $response = $twitter->statuses->mentionsTimeline();
        $this->assertTrue($response instanceof TwitterResponse);
    }

    /**
     * TODO: Add verification for ALL optional parameters
     */
    public function testFriendshipDestroy()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'friendships/destroy.json', Http\Request::METHOD_POST, 'friendships.destroy.twitter.json',
            array('screen_name' => 'twitter')
        ));
        $response = $twitter->friendships->destroy('twitter');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testBlockingCreate()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'blocks/create.json', Http\Request::METHOD_POST, 'blocks.create.twitter.json',
            array('screen_name' => 'twitter')
        ));
        $response = $twitter->blocks->create('twitter');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testBlockingList()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'blocks/list.json', Http\Request::METHOD_GET, 'blocks.list.json',
            array('cursor' => -1)
        ));
        $response = $twitter->blocks->list();
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testUsersShowAcceptsScreenNamesWithNumbers()
    {

        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient(
            $this->stubTwitter(
                'users/show.json',
                Http\Request::METHOD_GET,
                null,
                array('screen_name' => 'JuicyBurger661')
        ));
        //$id as screen_name with numbers
        $twitter->users->show('JuicyBurger661');
    }

    public function testUsersShowAcceptsIdAsStringArgument()
    {

        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient(
            $this->stubTwitter(
                'users/show.json',
                Http\Request::METHOD_GET,
                null,
                array('user_id' => 137307825)
            ));
        //$id as string
        $twitter->users->show('137307825');

    }

    public function testUsersShowAcceptsIdAsIntegerArgument()
    {

        $twitter = new Twitter\Twitter();
        $twitter->setHttpClient(
            $this->stubTwitter(
                'users/show.json',
                Http\Request::METHOD_GET,
                null,
                array('user_id' => 137307825)
            ));
        //$id as integer
        $twitter->users->show(137307825);
    }

    public function testBlockingIds()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'blocks/ids.json', Http\Request::METHOD_GET, 'blocks.ids.json',
            array('cursor' => -1)
        ));
        $response = $twitter->blocks->ids();
        $this->assertTrue($response instanceof TwitterResponse);
        $this->assertContains('23836616', $response->ids);
    }

    public function testBlockingDestroy()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'blocks/destroy.json', Http\Request::METHOD_POST, 'blocks.destroy.twitter.json',
            array('screen_name' => 'twitter')
        ));
        $response = $twitter->blocks->destroy('twitter');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    /**
     * @group ZF-6284
     */
    public function testTwitterObjectsSoNotShareSameHttpClientToPreventConflictingAuthentication()
    {
        $twitter1 = new Twitter\Twitter(array('username'=>'zftestuser1'));
        $twitter2 = new Twitter\Twitter(array('username'=>'zftestuser2'));
        $this->assertFalse($twitter1->getHttpClient() === $twitter2->getHttpClient());
    }

    public function testSearchTweets()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'search/tweets.json', Http\Request::METHOD_GET, 'search.tweets.json',
            array('q' => '#zf2')
        ));
        $response = $twitter->search->tweets('#zf2');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function testUsersSearch()
    {
        $twitter = new Twitter\Twitter;
        $twitter->setHttpClient($this->stubTwitter(
            'users/search.json', Http\Request::METHOD_GET, 'users.search.json',
            array('q' => 'Zend')
        ));
        $response = $twitter->users->search('Zend');
        $this->assertTrue($response instanceof TwitterResponse);
    }

    public function providerAdapterAlwaysReachableIfSpecifiedConfiguration() {
        $adapter = new CurlAdapter();

        return array(
            array(
                array(
                    'http_client_options' => array(
                        'adapter' => $adapter,
                    ),
                ),
                $adapter
            ),
            array(
                array(
                    'access_token' => array(
                        'token'  => 'some_token',
                        'secret' => 'some_secret',
                    ),
                    'http_client_options' => array(
                        'adapter' => $adapter,
                    ),
                ),
                $adapter
            ),
            array(
                array(
                    'access_token' => array(
                        'token'  => 'some_token',
                        'secret' => 'some_secret',
                    ),
                    'oauth_options' => array(
                        'consumerKey' => 'some_consumer_key',
                        'consumerSecret' => 'some_consumer_secret',
                    ),
                    'http_client_options' => array(
                        'adapter' => $adapter,
                    ),
                ),
                $adapter
            ),
        );
    }

    /**
     * @dataProvider providerAdapterAlwaysReachableIfSpecifiedConfiguration
      */
    public function testAdapterAlwaysReachableIfSpecified($config, $adapter)
    {
        $twitter = new \ZendService\Twitter\Twitter($config);
        $this->assertSame($adapter, $twitter->getHttpClient()->getAdapter());
    }
}
