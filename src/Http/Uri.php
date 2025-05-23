<?php

namespace Helix\Http;

use Psr\Http\Message\UriInterface;
use InvalidArgumentException;

class Uri implements UriInterface
{
    private const STANDARD_PORTS = [
        'http' => 80,
        'https' => 443,
        'ftp' => 21,
        'gopher' => 70,
        'nntp' => 119,
        'news' => 119,
        'telnet' => 23,
        'tn3270' => 23,
        'imap' => 143,
        'pop' => 110,
        'ldap' => 389,
    ];

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(
        string $scheme = '',
        string $host = '',
        ?int $port = null,
        string $path = '/',
        string $query = '',
        string $fragment = '',
        string $user = '',
        string $password = ''
    ) {
        $this->scheme = $this->filterScheme($scheme);
        $this->host = $this->filterHost($host);
        $this->port = $this->filterPort($port);
        $this->path = $this->filterPath($path);
        $this->query = $this->filterQuery($query);
        $this->fragment = $this->filterFragment($fragment);
        $this->userInfo = $this->filterUserInfo($user, $password);
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;
        
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null && !$this->isStandardPort()) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->isStandardPort() ? null : $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme($scheme): UriInterface
    {
        $scheme = $this->filterScheme($scheme);
        
        if ($this->scheme === $scheme) {
            return $this;
        }

        $new = clone $this;
        $new->scheme = $scheme;
        $new->port = $new->filterPort($this->port);
        
        return $new;
    }

    public function withUserInfo($user, $password = null): UriInterface
    {
        $userInfo = $this->filterUserInfo($user, $password);
        
        if ($this->userInfo === $userInfo) {
            return $this;
        }

        $new = clone $this;
        $new->userInfo = $userInfo;
        
        return $new;
    }

    public function withHost($host): UriInterface
    {
        $host = $this->filterHost($host);
        
        if ($this->host === $host) {
            return $this;
        }

        $new = clone $this;
        $new->host = $host;
        
        return $new;
    }

    public function withPort($port): UriInterface
    {
        $port = $this->filterPort($port);
        
        if ($this->port === $port) {
            return $this;
        }

        $new = clone $this;
        $new->port = $port;
        
        return $new;
    }

    public function withPath($path): UriInterface
    {
        $path = $this->filterPath($path);
        
        if ($this->path === $path) {
            return $this;
        }

        $new = clone $this;
        $new->path = $path;
        
        return $new;
    }

    public function withQuery($query): UriInterface
    {
        $query = $this->filterQuery($query);
        
        if ($this->query === $query) {
            return $this;
        }

        $new = clone $this;
        $new->query = $query;
        
        return $new;
    }

    public function withFragment($fragment): UriInterface
    {
        $fragment = $this->filterFragment($fragment);
        
        if ($this->fragment === $fragment) {
            return $this;
        }

        $new = clone $this;
        $new->fragment = $fragment;
        
        return $new;
    }

    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        if ($this->getAuthority() !== '' || $this->scheme === 'file') {
            $uri .= '//' . $this->getAuthority();
        }

        $uri .= $this->path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    private function filterScheme(string $scheme): string
    {
        $scheme = strtolower($scheme);
        $scheme = preg_replace('#:(//)?$#', '', $scheme);

        if ($scheme === '') {
            return '';
        }

        if (!array_key_exists($scheme, self::STANDARD_PORTS)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported scheme "%s"', $scheme)
            );
        }

        return $scheme;
    }

    private function filterHost(string $host): string
    {
        $host = strtolower($host);
        
        // Remove brackets from IPv6 addresses
        if (strpos($host, '[') === 0 && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        
        // Validate host (simple validation - consider using filter_var for more strict validation)
        if ($host !== '' && !preg_match('/^([a-z0-9\-._~%!$&\'()*+,;=]+|\[[a-f0-9:.]+\])$/i', $host)) {
            throw new InvalidArgumentException(
                sprintf('Invalid host "%s"', $host)
            );
        }

        return $host;
    }

    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(
                sprintf('Invalid port "%d"', $port)
            );
        }

        return $port;
    }

    private function filterPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        // Ensure path is properly encoded
        $path = preg_replace_callback(
            '/(?:[^a-zA-Z0-9_\-\.~:@&=\+\$,\/;%]+|%(?![A-Fa-f0-9]{2}))/',
            function ($match) {
                return rawurlencode($match[0]);
            },
            $path
        );

        // Remove dot segments
        $path = $this->removeDotSegments($path);

        // Ensure path starts with / if it's not empty and not a relative path
        if ($path !== '' && strpos($path, '/') !== 0 && $this->host !== '') {
            $path = '/' . $path;
        }

        return $path;
    }

    private function filterQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        // Ensure query is properly encoded
        $query = preg_replace_callback(
            '/(?:[^a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=%:@\/\?]+|%(?![A-Fa-f0-9]{2}))/',
            function ($match) {
                return rawurlencode($match[0]);
            },
            $query
        );

        return $query;
    }

    private function filterFragment(string $fragment): string
    {
        if ($fragment === '') {
            return '';
        }

        // Fragment uses same encoding as query
        return $this->filterQuery($fragment);
    }

    private function filterUserInfo(string $user, ?string $password): string
    {
        if ($user === '') {
            return '';
        }

        // Encode user
        $user = preg_replace_callback(
            '/(?:[^a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=]+|%(?![A-Fa-f0-9]{2}))/',
            function ($match) {
                return rawurlencode($match[0]);
            },
            $user
        );

        if ($password === null || $password === '') {
            return $user;
        }

        // Encode password
        $password = preg_replace_callback(
            '/(?:[^a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=]+|%(?![A-Fa-f0-9]{2}))/',
            function ($match) {
                return rawurlencode($match[0]);
            },
            $password
        );

        return $user . ':' . $password;
    }

    private function isStandardPort(): bool
    {
        return ($this->port === null || 
               (isset(self::STANDARD_PORTS[$this->scheme]) && 
                self::STANDARD_PORTS[$this->scheme] === $this->port));
    }

    private function removeDotSegments(string $path): string
    {
        $result = '';
        
        while (!empty($path)) {
            // A. Remove prefix of "../" or "./"
            if (strpos($path, '../') === 0) {
                $path = substr($path, 3);
            } elseif (strpos($path, './') === 0) {
                $path = substr($path, 2);
            }
            // B. Remove prefix of "/./" or "/.", replace with "/"
            elseif (strpos($path, '/./') === 0) {
                $path = substr($path, 2);
            } elseif ($path === '/.') {
                $path = '/';
            }
            // C. Remove prefix of "/../" or "/..", replace with "/" and remove last segment
            elseif (strpos($path, '/../') === 0) {
                $path = substr($path, 3);
                $result = substr($result, 0, strrpos($result, '/'));
            } elseif ($path === '/..') {
                $path = '/';
                $result = substr($result, 0, strrpos($result, '/'));
            }
            // D. Remove "." or ".." if they exist as complete segments
            elseif ($path === '.' || $path === '..') {
                $path = '';
            }
            // E. Move first path segment to result
            else {
                $pos = strpos($path, '/');
                if ($pos === false) {
                    $pos = strlen($path);
                }
                
                $segment = substr($path, 0, $pos);
                $result .= $segment;
                $path = substr($path, $pos);
            }
        }
        
        return $result;
    }
}