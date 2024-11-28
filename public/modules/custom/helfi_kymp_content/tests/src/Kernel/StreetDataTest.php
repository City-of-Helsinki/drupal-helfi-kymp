<?php

declare(strict_types=1);

namespace Drupal\Tests\helfi_kymp_content\Kernel;

use Drupal\helfi_kymp_content\Plugin\DataType\StreetData;
use Drupal\helfi_kymp_content\StreetDataService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\helfi_api_base\Traits\ApiTestTrait;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests street data service.
 */
class StreetDataTest extends KernelTestBase {

  use ApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'helfi_kymp_content',
  ];

  /**
   * Tests street data service.
   */
  public function testStreetDataService(): void {
    $this->setupMockHttpClient([
      new RequestException('test', new Request('GET', 'test')),
      new Response(body: 'invalid-xml'),
      new Response(body: file_get_contents(__DIR__ . '/../../fixtures/street_data.xml')),
    ]);

    $sut = $this->container->get(StreetDataService::class);

    // RequestException response.
    $data = $sut->getStreetData();
    $this->assertEmpty($data);

    // Invalid XML response.
    $data = $sut->getStreetData();
    $this->assertEmpty($data);

    // Fixture response.
    $data = $sut->getStreetData();
    $this->assertNotEmpty($data);

    foreach ($data as $id => $street) {
      $this->assertInstanceOf(StreetData::class, $street);
      $this->assertEquals($id, $street->get('id')->getValue());
    }

    $street = $data['YLRE_Katualue_alue.809'];
    $this->assertNotEmpty($street);
    $this->assertEquals('Kruunuhaankatu', $street->get('street_name')->getValue());
    $this->assertEquals("72.4", $street->get('length')->getValue());
    $this->assertEquals(3, $street->get('maintenance_class')->getValue());
  }

}
