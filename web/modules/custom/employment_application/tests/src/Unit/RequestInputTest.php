<?php

declare(strict_types=1);

namespace Drupal\Tests\employment_application\Unit;

use Drupal\employment_application\RequestInput;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers reading request values of unexpected shape.
 *
 * @see \Drupal\employment_application\RequestInput
 */
#[Group('employment_application')]
final class RequestInputTest extends TestCase {

  /**
   * Scalars come back as strings; arrays and absent keys as the default.
   */
  public function testString(): void {
    $query = Request::create('/x?q=evict&n=5&empty=&list[]=a&map[k]=v')->query;

    $this->assertSame('evict', RequestInput::string($query, 'q'));
    $this->assertSame('5', RequestInput::string($query, 'n'));
    $this->assertSame('', RequestInput::string($query, 'empty', 'fallback'));
    $this->assertSame('', RequestInput::string($query, 'list'));
    $this->assertSame('all', RequestInput::string($query, 'map', 'all'));
    $this->assertSame('all', RequestInput::string($query, 'missing', 'all'));
  }

  /**
   * Absent and array values are NULL, so "was it sent" stays answerable.
   */
  public function testStringOrNull(): void {
    $query = Request::create('/x?id=faq_12&list[]=a')->query;

    $this->assertSame('faq_12', RequestInput::stringOrNull($query, 'id'));
    $this->assertNull(RequestInput::stringOrNull($query, 'list'));
    $this->assertNull(RequestInput::stringOrNull($query, 'missing'));
  }

  /**
   * Raw reads return arrays, which InputBag::get() cannot.
   */
  public function testRawReturnsArraysFromTheFormBody(): void {
    $body = Request::create('/x', 'POST', ['address' => ['city' => 'Boise'], 'name' => 'A'])->request;

    $this->assertSame(['city' => 'Boise'], RequestInput::raw($body, 'address'));
    $this->assertSame('A', RequestInput::raw($body, 'name'));
    $this->assertNull(RequestInput::raw($body, 'missing'));
    $this->assertSame('', RequestInput::raw($body, 'missing', ''));
    $this->assertSame('', RequestInput::string($body, 'address'));
  }

}
