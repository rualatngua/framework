<?php
/*
  +------------------------------------------------------------------------+
  | PhalconEye CMS                                                         |
  +------------------------------------------------------------------------+
  | Copyright (c) 2013 PhalconEye Team (http://phalconeye.com/)            |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconeye.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Author: Ivan Vorontsov <ivan.vorontsov@phalconeye.com>                 |
  +------------------------------------------------------------------------+
*/

namespace Engine\Helper;

use Engine\Helper;
use Phalcon\DI;
use Phalcon\Tag;

/**
 * I18n Formatter
 *
 * @category  PhalconEye
 * @package   Engine\Helper
 * @author    Ivan Vorontsov <ivan.vorontsov@phalconeye.com>
 * @copyright 2013 PhalconEye Team
 * @license   New BSD License
 * @link      http://phalconeye.com/
 */
class Formatter extends Helper
{
    /**
     * Get current url.
     *
     * @param mixed $number Number to format.
     * @param mixed $style  Output style.
     *
     * @return mixed
     */
    protected function _formatNumber($number, $style = \NumberFormatter::DECIMAL)
    {
        $locale = $this->getDI()->get('session')->get('locale');
        $formatter = new \NumberFormatter($locale, $style);

        return $formatter->format($number);
    }

    /**
     * Format currency.
     *
     * @param mixed $number Number to format.
     *
     * @return mixed
     */
    protected function _formatCurrency($number)
    {
        return $this->_formatNumber($number, \NumberFormatter::CURRENCY);
    }
}