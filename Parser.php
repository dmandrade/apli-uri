<?php
/**
 *  Copyright (c) 2018 Danilo Andrade
 *
 *  This file is part of the apli project.
 *
 * @project apli
 * @file Parser.php
 * @author Danilo Andrade <danilo@webbingbrasil.com.br>
 * @date 27/08/18 at 10:27
 */

/**
 * Created by PhpStorm.
 * User: Danilo
 * Date: 25/08/2018
 * Time: 12:18
 */

namespace Apli\Uri;


class Parser
{
    const URI_COMPONENTS = [
        'scheme' => null, 'user' => null, 'pass' => null, 'host' => null,
        'port' => null, 'path' => '', 'query' => null, 'fragment' => null,
    ];

    /**
     * Parse an URI string into its components.
     *
     * This method parses a URI and returns an associative array containing any
     * of the various components of the URI that are present.
     *
     * @see https://tools.ietf.org/html/rfc3986
     * @see https://tools.ietf.org/html/rfc3986#section-2
     *
     * @param string $uri
     *
     * @throws Exception if the URI contains invalid characters
     *
     * @return array
     */
    public function parse($uri)
    {
        static $pattern = '/[\x00-\x1f\x7f]/';
        //simple URI which do not need any parsing
        static $simple_uri = [
            '' => [],
            '#' => ['fragment' => ''],
            '?' => ['query' => ''],
            '?#' => ['query' => '', 'fragment' => ''],
            '/' => ['path' => '/'],
            '//' => ['host' => ''],
        ];
        if (isset($simple_uri[$uri])) {
            return array_merge(self::URI_COMPONENTS, $simple_uri[$uri]);
        }
        if (preg_match($pattern, $uri)) {
            throw Exception::createFromInvalidCharacters($uri);
        }
        //if the first character is a known URI delimiter parsing can be simplified
        $first_char = $uri[0];
        //The URI is made of the fragment only
        if ('#' === $first_char) {
            $components = self::URI_COMPONENTS;
            $components['fragment'] = (string)substr($uri, 1);
            return $components;
        }
        //The URI is made of the query and fragment
        if ('?' === $first_char) {
            $components = self::URI_COMPONENTS;
            list($components['query'], $components['fragment']) = explode('#', substr($uri, 1), 2) + [1 => null];
            return $components;
        }
        //The URI does not contain any scheme part
        if (0 === strpos($uri, '//')) {
            return $this->parseSchemeSpecificPart($uri);
        }
        //The URI is made of a path, query and fragment
        if ('/' === $first_char || false === strpos($uri, ':')) {
            return $this->parsePathQueryAndFragment($uri);
        }
        //Fallback parser
        return $this->fallbackParser($uri);
    }

    /**
     * Extract components from a URI without a scheme part.
     *
     * The URI MUST start with the authority component
     * preceded by its delimiter the double slash ('//')
     *
     * Example: //user:pass@host:42/path?query#fragment
     *
     * The authority MUST adhere to the RFC3986 requirements.
     *
     * If the URI contains a path component, it MUST be empty or absolute
     * according to RFC3986 path classification.
     *
     * This method returns an associative array containing all URI components.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2
     * @see https://tools.ietf.org/html/rfc3986#section-3.3
     *
     * @param string $uri
     *
     * @throws Exception If any component of the URI is invalid
     *
     * @return array
     */
    protected function parseSchemeSpecificPart($uri)
    {
        //We remove the authority delimiter
        $remainingUri = (string)substr($uri, 2);
        $components = self::URI_COMPONENTS;
        //Parsing is done from the right upmost part to the left
        //1 - detect fragment, query and path part if any
        list($remainingUri, $components['fragment']) = explode('#', $remainingUri, 2) + [1 => null];
        list($remainingUri, $components['query']) = explode('?', $remainingUri, 2) + [1 => null];
        if (false !== strpos($remainingUri, '/')) {
            list($remainingUri, $components['path']) = explode('/', $remainingUri, 2) + [1 => null];
            $components['path'] = '/'.$components['path'];
        }
        //2 - The $remainingUri represents the authority part
        //if the authority part is empty parsing is simplified
        if ('' === $remainingUri) {
            $components['host'] = '';
            return $components;
        }
        //otherwise we split the authority into the user information and the hostname parts
        $parts = explode('@', $remainingUri, 2);
        $hostname = isset($parts[1]) ? $parts[1] : $parts[0];
        $userInfo = isset($parts[1]) ? $parts[0] : null;
        if (null !== $userInfo) {
            list($components['user'], $components['pass']) = explode(':', $userInfo, 2) + [1 => null];
        }
        list($components['host'], $components['port']) = $this->parseHostname($hostname);
        return $components;
    }

    /**
     * Parse and validate the URI hostname.
     *
     * @param string $hostname
     *
     * @throws Exception If the hostname is invalid
     *
     * @return array
     */
    protected function parseHostname($hostname)
    {
        if (false === strpos($hostname, '[')) {
            list($host, $port) = explode(':', $hostname, 2) + [1 => null];
            return [$this->filterHost($host), $this->filterPort($port)];
        }
        $delimiterOffset = strpos($hostname, ']') + 1;
        if (isset($hostname[$delimiterOffset]) && ':' !== $hostname[$delimiterOffset]) {
            throw Exception::createFromInvalidHostname($hostname);
        }
        return [
            $this->filterHost(substr($hostname, 0, $delimiterOffset)),
            $this->filterPort(substr($hostname, ++$delimiterOffset)),
        ];
    }

    /**
     * validate the host component
     *
     * @param string|null $host
     *
     * @throws Exception If the hostname is invalid
     *
     * @return string|null
     */
    protected function filterHost($host)
    {
        if (null === $host || $this->isHost($host)) {
            return $host;
        }
        throw Exception::createFromInvalidHost($host);
    }

    /**
     * Returns whether a hostname is valid.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $host
     *
     * @return bool
     */
    public function isHost($host)
    {
        return '' === $host
            || $this->isIpHost($host)
            || $this->isRegisteredName($host);
    }

    /**
     * Validate a IPv6/IPvfuture host
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     * @see http://tools.ietf.org/html/rfc6874#section-2
     * @see http://tools.ietf.org/html/rfc6874#section-4
     *
     * @param string $host
     *
     * @return bool
     */
    private function isIpHost($host)
    {
        if ('[' !== (isset($host[0]) ? $host[0] : '') || ']' !== substr($host, -1)) {
            return false;
        }
        $ip = substr($host, 1, -1);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }

        static $ipFuture = '/^
            v(?<version>[A-F0-9])+\.
            (?:
                (?<unreserved>[a-z0-9_~\-\.])|
                (?<sub_delims>[!$&\'()*+,;=:])  # also include the : character
            )+
        $/ix';

        if (preg_match($ipFuture, $ip, $matches) && !in_array($matches['version'], ['4', '6'], true)) {
            return true;
        }

        if (false === ($pos = strpos($ip, '%'))) {
            return false;
        }

        static $genDelims = '/[:\/?#\[\]@ ]/'; // Also includes space.

        if (preg_match($genDelims, rawurldecode(substr($ip, $pos)))) {
            return false;
        }

        $ip = substr($ip, 0, $pos);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        //Only the address block fe80::/10 can have a Zone ID attach to
        //let's detect the link local significant 10 bits
        static $addressBlock = "\xfe\x80";
        return substr(inet_pton($ip) & $addressBlock, 0, 2) === $addressBlock;
    }

    /**
     * Returns whether the host is an IPv4 or a registered named
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $host
     *
     * @throws MissingIdnSupport if the registered name contains non-ASCII characters
     *                           and IDN support or ICU requirement are not available or met.
     *
     * @return bool
     */
    protected function isRegisteredName($host)
    {
        // Note that unreserved is purposely missing . as it is used to separate labels.
        static $regName = '/(?(DEFINE)
                (?<unreserved>[a-z0-9_~\-])
                (?<sub_delims>[!$&\'()*+,;=])
                (?<encoded>%[A-F0-9]{2})
                (?<regName>(?:(?&unreserved)|(?&sub_delims)|(?&encoded))*)
            )
            ^(?:(?&regName)\.)*(?&regName)\.?$/ix';

        if (preg_match($regName, $host)) {
            return true;
        }

        //to test IDN host non-ascii characters must be present in the host
        static $idnPattern = '/[^\x20-\x7f]/';
        if (!preg_match($idnPattern, $host)) {
            return false;
        }
        static $idnSupport = null;
        $idnSupport = $idnSupport ?: function_exists('idn_to_ascii') && defined('INTL_IDNA_VARIANT_UTS46');

        if ($idnSupport) {
            idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46, $arr);
            return 0 === $arr['errors'];
        }

        // @codeCoverageIgnoreStart
        // added because it is not possible in travis to disabled the ext/intl extension
        // see travis issue https://github.com/travis-ci/travis-ci/issues/4701
        throw new MissingIdnSupport(sprintf('the host `%s` could not be processed for IDN. Verify that ext/intl is installed for IDN support and that ICU is at least version 4.6.', $host));
        // @codeCoverageIgnoreEnd
    }

    /**
     * Validate a port number.
     *
     * An exception is raised for ports outside the established TCP and UDP port ranges.
     *
     * @param mixed $port the port number
     *
     * @throws Exception If the port number is invalid.
     *
     * @return null|int
     */
    protected function filterPort($port)
    {
        static $pattern = '/^[0-9]+$/';
        if (null === $port || false === $port || '' === $port) {
            return null;
        }
        if (!preg_match($pattern, (string)$port)) {
            throw Exception::createFromInvalidPort($port);
        }
        return (int)$port;
    }

    /**
     * Extract Components from an URI without scheme or authority part.
     *
     * The URI contains a path component and MUST adhere to path requirements
     * of RFC3986. The path can be
     *
     * <code>
     * path   = path-abempty    ; begins with "/" or is empty
     *        / path-absolute   ; begins with "/" but not "//"
     *        / path-noscheme   ; begins with a non-colon segment
     *        / path-rootless   ; begins with a segment
     *        / path-empty      ; zero characters
     * </code>
     *
     * ex: path?q#f
     * ex: /path
     * ex: /pa:th#f
     *
     * This method returns an associative array containing all URI components.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.3
     *
     * @param string $uri
     *
     * @throws Exception If the path component is invalid
     *
     * @return array
     */
    protected function parsePathQueryAndFragment($uri)
    {
        //No scheme is present so we ensure that the path respects RFC3986
        if (false !== ($pos = strpos($uri, ':')) && false === strpos(substr($uri, 0, $pos), '/')) {
            throw Exception::createFromInvalidPath($uri);
        }

        $components = self::URI_COMPONENTS;
        //Parsing is done from the right upmost part to the left
        //1 - detect the fragment part if any
        list($remaining_uri, $components['fragment']) = explode('#', $uri, 2) + [1 => null];
        //2 - detect the query and the path part
        list($components['path'], $components['query']) = explode('?', $remaining_uri, 2) + [1 => null];

        return $components;
    }

    /**
     * Extract components from an URI containing a colon.
     *
     * Depending on the colon ":" position and on the string
     * composition before the presence of the colon, the URI
     * will be considered to have an scheme or not.
     *
     * <ul>
     * <li>In case no valid scheme is found according to RFC3986 the URI will
     * be parsed as an URI without a scheme and an authority</li>
     * <li>In case an authority part is detected the URI specific part is parsed
     * as an URI without scheme</li>
     * </ul>
     *
     * ex: email:johndoe@thephpleague.com?subject=Hellow%20World!
     *
     * This method returns an associative array containing all
     * the URI components.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.1
     * @see Parser::parsePathQueryAndFragment
     * @see Parser::parseSchemeSpecificPart
     *
     * @param string $uri
     *
     * @throws Exception If the URI scheme component is empty
     *
     * @return array
     */
    protected function fallbackParser($uri)
    {
        //1 - we split the URI on the first detected colon character
        $parts = explode(':', $uri, 2);
        $remainingUri = isset($parts[1]) ? $parts[1] : $parts[0];
        $scheme = isset($parts[1]) ? $parts[0] : null;
        //1.1 - a scheme can not be empty (ie a URI can not start with a colon)
        if ('' === $scheme) {
            throw Exception::createFromInvalidScheme($uri);
        }

        //2 - depending on the scheme presence and validity we will differ the parsing
        //2.1 - If the scheme part is invalid the URI may be an URI with a path-noscheme
        //      let's differ the parsing to the Parser::parsePathQueryAndFragment method
        if (!$this->isScheme($scheme)) {
            return $this->parsePathQueryAndFragment($uri);
        }

        $components = self::URI_COMPONENTS;
        $components['scheme'] = $scheme;

        //2.2 - if no scheme specific part is detect parsing is finished
        if ('' == $remainingUri) {
            return $components;
        }

        //2.3 - if the scheme specific part is a double forward slash
        if ('//' === $remainingUri) {
            $components['host'] = '';
            return $components;
        }
        //2.4 - if the scheme specific part starts with double forward slash
        //      we differ the remaining parsing to the Parser::parseSchemeSpecificPart method
        if (0 === strpos($remainingUri, '//')) {
            $components = $this->parseSchemeSpecificPart($remainingUri);
            $components['scheme'] = $scheme;
            return $components;
        }
        //2.5 - Parsing is done from the right upmost part to the left from the scheme specific part
        //2.5.1 - detect the fragment part if any
        list($remainingUri, $components['fragment']) = explode('#', $remainingUri, 2) + [1 => null];
        //2.5.2 - detect the part and query part if any
        list($components['path'], $components['query']) = explode('?', $remainingUri, 2) + [1 => null];
        return $components;
    }

    /**
     * Returns whether a scheme is valid.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.1
     *
     * @param string $scheme
     *
     * @return bool
     */
    public function isScheme($scheme)
    {
        static $pattern = '/^[a-z][a-z\+\.\-]*$/i';
        return '' === $scheme || preg_match($pattern, $scheme);
    }
}
