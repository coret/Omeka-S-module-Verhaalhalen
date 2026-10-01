<?php
namespace Verhaalhalen\Mvc\Exception;

use Omeka\Mvc\Exception\InvalidJsonException;

/**
 * A request the Verhaalhalen formats cannot answer.
 *
 * Extends core's InvalidJsonException only because that is the exception
 * ApiJsonStrategy maps to HTTP 400; nothing here is about JSON input.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class BadRequestException extends InvalidJsonException
{
}
