<?php
/**
 *  Copyright (c) 2018 Danilo Andrade
 *
 *  This file is part of the apli project.
 *
 *  @project apli
 *  @file Url.php
 *  @author Danilo Andrade <danilo@webbingbrasil.com.br>
 *  @date 25/08/18 at 12:00
 */

/**
 * Created by PhpStorm.
 * User: Danilo
 * Date: 25/08/2018
 * Time: 11:06
 */

namespace Apli\Uri;

/**
 * Class Url
 * @package Apli\Uri
 */
class Url extends AbstractUri
{
    /**
     * Default schemes
     *
     * @var array
     */
    protected static $standardSchemes = [
        'http' => 80,
        'https' => 443,
    ];

    /**
     * Tell whether the current is in valid state.
     *
     * The object validity depends on the scheme. This method
     * MUST be implemented on every object
     *
     * @return bool
     */
    protected function isValidUri()
    {
        return '' !== $this->host
            && (null === $this->scheme || isset(static::$standardSchemes[$this->scheme]))
            && !('' != $this->scheme && null === $this->host);
    }

    /**
     * Filter the Port component.
     *
     * @param int|null $port
     *
     * @throws UriException if the port is invalid
     *
     * @return int|null
     */
    protected static function filterPort($port)
    {
        if (null === $port) {
            return $port;
        }
        if (1 > $port || 65535 < $port) {
            throw UriException::createFromInvalidPort($port);
        }
        return $port;
    }

    /**
     * Create a new instance from the environment.
     *
     * @param array $server the server and execution environment information array typically ($_SERVER)
     *
     * @return static
     */
    public static function createFromServer(array $server)
    {
        list($user, $pass) = static::fetchUserInfo($server);
        list($host, $port) = static::fetchHostname($server);
        list($path, $query) = static::fetchRequestUri($server);
        return new static(static::fetchScheme($server), $user, $pass, $host, $port, $path, $query);
    }

    /**
     * Returns the environment scheme.
     *
     * @param array $server the environment server typically $_SERVER
     * @return string
     */
    protected static function fetchScheme(array $server)
    {
        $server += ['HTTPS' => ''];
        $res = filter_var($server['HTTPS'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $res !== false ? 'https' : 'http';
    }

    /**
     * Returns the environment user info.
     *
     * @param array $server the environment server typically $_SERVER
     * @return array
     */
    protected static function fetchUserInfo(array $server)
    {
        $server += ['PHP_AUTH_USER' => null, 'PHP_AUTH_PW' => null, 'HTTP_AUTHORIZATION' => ''];
        $user = $server['PHP_AUTH_USER'];
        $pass = $server['PHP_AUTH_PW'];

        if (0 === strpos(strtolower($server['HTTP_AUTHORIZATION']), 'basic')) {
            list($user, $pass) = explode(':', base64_decode(substr($server['HTTP_AUTHORIZATION'], 6)), 2) + [1 => null];
        }

        if (null !== $user) {
            $user = rawurlencode($user);
        }

        if (null !== $pass) {
            $pass = rawurlencode($pass);
        }

        return [$user, $pass];
    }

    /**
     * Returns the environment host.
     *
     * @param array $server the environment server typically $_SERVER
     * @return array
     *
     * @throws UriException If the host can not be detected
     */
    protected static function fetchHostname(array $server)
    {
        $server += ['SERVER_PORT' => null];

        if (null !== $server['SERVER_PORT']) {
            $server['SERVER_PORT'] = (int) $server['SERVER_PORT'];
        }

        if (isset($server['HTTP_HOST'])) {
            preg_match(',^(?<host>(\[.*\]|[^:])*)(\:(?<port>[^/?\#]*))?$,x', $server['HTTP_HOST'], $matches);
            return [
                $matches['host'],
                isset($matches['port']) ? (int) $matches['port'] : $server['SERVER_PORT'],
            ];
        }

        if (!isset($server['SERVER_ADDR'])) {
            throw new UriException('Hostname could not be detected');
        }

        if (!filter_var($server['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $server['SERVER_ADDR'] = '['.$server['SERVER_ADDR'].']';
        }

        return [$server['SERVER_ADDR'], $server['SERVER_PORT']];
    }

    /**
     * Returns the environment path.
     *
     * @param array $server the environment server typically $_SERVER
     * @return array
     */
    protected static function fetchRequestUri(array $server)
    {
        $server += ['IIS_WasUrlRewritten' => null, 'UNENCODED_URL' => '', 'PHP_SELF' => '', 'QUERY_STRING' => null];
        if ('1' === $server['IIS_WasUrlRewritten'] && '' !== $server['UNENCODED_URL']) {
            return explode('?', $server['UNENCODED_URL'], 2) + [1 => null];
        }

        if (isset($server['REQUEST_URI'])) {
            list($path, ) = explode('?', $server['REQUEST_URI'], 2);
            $query = ('' !== $server['QUERY_STRING']) ? $server['QUERY_STRING'] : null;
            return [$path, $query];
        }

        return [$server['PHP_SELF'], $server['QUERY_STRING']];
    }
}
