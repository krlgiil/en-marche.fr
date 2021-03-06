<?php

namespace Tests\AppBundle\Security;

use AppBundle\DataFixtures\ORM\LoadAdherentData;
use AppBundle\DataFixtures\ORM\LoadAdminData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AppBundle\Controller\ControllerTestTrait;
use Tests\AppBundle\SqliteWebTestCase;

/**
 * @group time-sensitive
 * @group functional
 */
class InactiveAdminDisconnectionTest extends SqliteWebTestCase
{
    use ControllerTestTrait;

    public function testLogoutInactiveAdmin()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/login');

        // connect as admin
        $this->client->submit($crawler->selectButton('Je me connecte')->form([
            '_admin_email' => 'titouan.galopin@en-marche.fr',
            '_admin_password' => 'secret!12345',
        ]));

        $this->client->request(Request::METHOD_GET, '/admin/app/media/list');
        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // wait
        sleep(1900);

        // go to another page
        $this->client->request(Request::METHOD_GET, '/admin/dashboard');

        // should be redirected to logout
        $this->assertClientIsRedirectedTo('/admin/logout', $this->client, false);
    }

    public function testNoLogoutInactiveAdherent()
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/espace-adherent/connexion');

        // connect as adherent
        $this->client->submit($crawler->selectButton('Je me connecte')->form([
            '_adherent_email' => 'carl999@example.fr',
            '_adherent_password' => 'secret!12345',
        ]));

        $this->client->request(Request::METHOD_GET, '/espace-adherent/documents');

        // wait
        sleep(1900);

        // go to another page
        $this->client->request(Request::METHOD_GET, '/espace-adherent/mon-profil');

        // status code should be 200 OK, because there is no redirection to disconnect
        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());
    }

    protected function setUp()
    {
        parent::setUp();

        $this->init([
            LoadAdminData::class,
            LoadAdherentData::class,
        ]);
    }

    protected function tearDown()
    {
        $this->kill();

        parent::tearDown();
    }
}
