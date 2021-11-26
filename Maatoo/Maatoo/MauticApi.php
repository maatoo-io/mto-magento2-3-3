<?php
/**
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 *
 * @see        http://mautic.org
 *
 * @license     MIT http://opensource.org/licenses/MIT
 */

namespace Maatoo\Maatoo;

use Maatoo\Maatoo\Auth\AuthInterface;
use Maatoo\Maatoo\Exception\ContextNotFoundException;

/**
 * Mautic API Factory.
 */
class MauticApi
{
    /**
     * Get an API context object.
     *
     * @param string        $apiContext API context (leads, forms, etc)
     * @param AuthInterface $auth       API Auth object
     * @param string        $baseUrl    Base URL for API endpoints
     *
     * @return Api\Api
     *
     * @throws ContextNotFoundException
     *
     * @deprecated
     */
    public static function getContext($apiContext, AuthInterface $auth, $baseUrl = '')
    {
        static $contexts = [];

        $apiContext = ucfirst($apiContext);

        if (!isset($context[$apiContext])) {
            $class = 'Maatoo\\Maatoo\\Api\\'.$apiContext;

            if (!class_exists($class)) {
                throw new ContextNotFoundException("A context of '$apiContext' was not found.");
            }

            $contexts[$apiContext] = new $class($auth, $baseUrl);
        }

        return $contexts[$apiContext];
    }

    /**
     * Get an API context object.
     *
     * @param string        $apiContext API context (leads, forms, etc)
     * @param AuthInterface $auth       API Auth object
     * @param string        $baseUrl    Base URL for API endpoints
     *
     * @return Api\Api
     *
     * @throws ContextNotFoundException
     */
    public function newApi($apiContext, AuthInterface $auth, $baseUrl = '')
    {
        $apiContext = ucfirst($apiContext);

        $class = 'Maatoo\\Maatoo\\Api\\'.$apiContext;

        if (!class_exists($class)) {
            throw new ContextNotFoundException("A context of '$apiContext' was not found.");
        }

        return new $class($auth, $baseUrl);
    }
}
