<?php

declare(strict_types=1);

namespace XipHub\XipFax\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use stdClass;

/**
 * The API's document, openapi.json, as the repository holds it, read so that the specs can read what
 * the SDK sends and is answered against it: the operation a request is, and each thing in it the
 * document refuses, from its address, its key and its version to each parameter and its body; and
 * what in an answer or an event's body the document's schema refuses. It reads the schema keywords
 * the document uses, and refuses a schema with one it doesn't read, so that a document that comes to
 * say more than this reads fails the specs rather than passing unread. A value is JSON as json_decode
 * reads it into objects: an object is a stdClass, and an array a list.
 */
final class Document
{
    /** The methods a path's operations may have, in the order they are read. */
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** A parameter's place in a path, {fax_id}. */
    private const PATH_PARAMETER = '/\{([^}]+)\}/';

    /** The keywords that describe a value without constraining it. */
    private const ANNOTATIONS = ['description', 'title', 'default', 'examples'];

    /** The document's info.version, the version of the API it is. */
    public readonly string $version;

    /** @var list<Operation> the document's operations, by their paths in order and the method within each */
    public readonly array $operations;

    /** @var array<string, stdClass> the schema of the body each event posts, by the event's name */
    public readonly array $webhooks;

    private readonly stdClass $raw;

    private readonly UriInterface $server;

    private function __construct(string $path)
    {
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException("$path doesn't read");
        }
        try {
            $raw = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("$path: {$e->getMessage()}", 0, $e);
        }
        if (!$raw instanceof stdClass) {
            throw new RuntimeException("$path is no JSON object");
        }
        $this->raw = $raw;

        $info = $raw->info ?? null;
        $this->version = $info instanceof stdClass && is_string($info->version ?? null) ? $info->version : '';

        $servers = $raw->servers ?? null;
        $first = is_array($servers) ? ($servers[0] ?? null) : null;
        if (!$first instanceof stdClass) {
            throw new RuntimeException("$path names no server");
        }
        try {
            $this->server = new Uri(is_string($first->url ?? null) ? $first->url : '');
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException("$path's server: {$e->getMessage()}", 0, $e);
        }

        $operations = [];
        $paths = $raw->paths ?? null;
        foreach ($paths instanceof stdClass ? self::names($paths) : [] as $route) {
            $item = $paths->{$route};
            if (!$item instanceof stdClass) {
                continue;
            }
            foreach (self::METHODS as $method) {
                $entry = $item->{$method} ?? null;
                if ($entry instanceof stdClass) {
                    $operations[] = $this->operationAt($route, $method, $item, $entry);
                }
            }
        }
        $this->operations = $operations;

        $webhooks = [];
        $posted = $raw->webhooks ?? null;
        foreach ($posted instanceof stdClass ? self::names($posted) : [] as $name) {
            $item = $posted->{$name};
            $post = $item instanceof stdClass ? ($item->post ?? null) : null;
            try {
                $schema = $post instanceof stdClass ? $this->bodySchema($post) : null;
            } catch (RuntimeException $e) {
                throw new RuntimeException("the webhook $name posts no JSON body the document gives: {$e->getMessage()}", 0, $e);
            }
            if ($schema === null) {
                throw new RuntimeException("the webhook $name posts no JSON body the document gives");
            }
            $webhooks[$name] = $schema;
        }
        $this->webhooks = $webhooks;
    }

    /** Reads the document at the path. */
    public static function load(string $path): self
    {
        return new self($path);
    }

    /** The operation of the id. */
    public function operation(string $id): Operation
    {
        foreach ($this->operations as $operation) {
            if ($operation->id === $id) {
                return $operation;
            }
        }
        throw new RuntimeException("the document has no operation $id");
    }

    /**
     * Reads a request against the document: the id of the operation it is, and each thing in it the
     * document refuses, none where it fits. The body is the request's as it was sent, and the version
     * the one its Xip-Version must name.
     *
     * @return array{string, list<string>}
     */
    public function readRequest(RequestInterface $request, string $body, string $version): array
    {
        $found = $this->find($request);
        if ($found === null) {
            return ['', [self::noOperation($request)]];
        }
        [$operation, $values] = $found;
        $refused = [];
        $keys = $request->getHeader('Authorization');
        if (count($keys) !== 1 || preg_match('/^Bearer \S+$/D', $keys[0]) !== 1) {
            $refused[] = sprintf('Authorization is %s, not one bearer key', self::quoted($keys));
        }
        $versions = $request->getHeader('Xip-Version');
        if (count($versions) !== 1 || $versions[0] !== $version) {
            $refused[] = sprintf('Xip-Version is %s, not %s', self::quoted($versions), $version);
        }

        $query = self::query($request->getUri()->getQuery());
        $takesQuery = [];
        foreach ($operation->parameters as $parameter) {
            $given = [];
            switch ($parameter['in']) {
                case 'query':
                    $takesQuery[$parameter['name']] = true;
                    $given = $query[$parameter['name']] ?? [];
                    break;
                case 'header':
                    $given = $request->getHeader($parameter['name']);
                    break;
                case 'path':
                    if (array_key_exists($parameter['name'], $values)) {
                        $given = [$values[$parameter['name']]];
                    }
                    break;
            }
            $at = $parameter['in'] . ' ' . $parameter['name'];
            if ($given === []) {
                if ($parameter['required']) {
                    $refused[] = "$at is missing";
                }
            } elseif (count($given) > 1) {
                $refused[] = sprintf('%s is sent %d times', $at, count($given));
            } else {
                array_push($refused, ...$this->checkText($parameter['schema'], $given[0], $at));
            }
        }
        $names = array_map('strval', array_keys($query));
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if (!isset($takesQuery[$name])) {
                $refused[] = sprintf('query %s is no parameter of %s', $name, $operation->id);
            }
        }

        if ($operation->body !== null) {
            if (!str_starts_with($request->getHeader('Content-Type')[0] ?? '', 'application/json')) {
                $refused[] = 'the body is not sent as JSON';
            }
            try {
                $value = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
                array_push($refused, ...$this->check($operation->body, $value, 'body'));
            } catch (JsonException $e) {
                $refused[] = "the body is no JSON: {$e->getMessage()}";
            }
        } elseif ($body !== '') {
            $refused[] = 'a body the operation takes none of';
        }
        return [$operation->id, $refused];
    }

    /**
     * Reads an answer to a request against the document: what in it the operation's answer for its
     * status refuses, none where it fits. An empty body is an answer that carries none.
     *
     * @return list<string>
     */
    public function readAnswer(RequestInterface $request, int $status, string $body): array
    {
        $found = $this->find($request);
        if ($found === null) {
            return [self::noOperation($request)];
        }
        $operation = $found[0];
        $answer = $operation->responses->{(string) $status} ?? null;
        if ($answer === null) {
            return [sprintf('%s gives no %d', $operation->id, $status)];
        }
        try {
            $response = $this->resolve($answer);
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }
        $content = $response->content ?? null;
        if (!$content instanceof stdClass) {
            return $body !== '' ? [sprintf("%s's %d carries no body", $operation->id, $status)] : [];
        }
        $media = $content->{'application/json'} ?? null;
        $schema = $media instanceof stdClass ? ($media->schema ?? null) : null;
        try {
            $value = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [sprintf("%s's %d is no JSON: %s", $operation->id, $status, $e->getMessage())];
        }
        return $this->check($schema instanceof stdClass ? $schema : new stdClass(), $value, 'answer');
    }

    /**
     * What the operation's Xip-Version parameter refuses in the version, none where it takes it.
     *
     * @return list<string>
     */
    public function versionRefused(Operation $operation, string $version): array
    {
        foreach ($operation->parameters as $parameter) {
            if ($parameter['in'] === 'header' && $parameter['name'] === 'Xip-Version') {
                return $this->checkText($parameter['schema'], $version, 'header Xip-Version');
            }
        }
        return ["{$operation->id} takes no Xip-Version"];
    }

    /**
     * What the schema refuses in the value, each at its place from $at; none where it fits.
     *
     * @return list<string>
     */
    public function check(stdClass $schema, mixed $value, string $at): array
    {
        $refused = [];
        foreach (self::names($schema) as $keyword) {
            $rule = $schema->{$keyword};
            switch ($keyword) {
                case '$ref':
                    try {
                        $target = $this->pointer(self::show($rule));
                    } catch (RuntimeException $e) {
                        $refused[] = "$at: {$e->getMessage()}";
                        break;
                    }
                    array_push($refused, ...$this->check($target, $value, $at));
                    break;
                case 'type':
                    if (!self::hasType($rule, $value)) {
                        $refused[] = sprintf('%s is %s, not %s', $at, self::typeOf($value), self::show($rule));
                    }
                    break;
                case 'enum':
                    if (!self::inList($rule, $value)) {
                        $refused[] = sprintf('%s is %s, none of %s', $at, self::show($value), self::show($rule));
                    }
                    break;
                case 'const':
                    if (!self::same($rule, $value)) {
                        $refused[] = sprintf('%s is %s, not %s', $at, self::show($value), self::show($rule));
                    }
                    break;
                case 'minLength':
                case 'maxLength':
                    if (!is_string($value) || !self::isNumber($rule)) {
                        break;
                    }
                    $length = mb_strlen($value, 'UTF-8');
                    if (($keyword === 'minLength' && $length < $rule) || ($keyword === 'maxLength' && $length > $rule)) {
                        $refused[] = sprintf('%s has %d characters, past its %s of %s', $at, $length, $keyword, self::show($rule));
                    }
                    break;
                case 'pattern':
                    if (!is_string($value)) {
                        break;
                    }
                    $pattern = self::show($rule);
                    $fault = null;
                    set_error_handler(static function (int $level, string $message) use (&$fault): bool {
                        $fault = $message;
                        return true;
                    });
                    try {
                        $matched = preg_match(self::delimited($pattern), $value);
                    } finally {
                        restore_error_handler();
                    }
                    if ($fault !== null) {
                        $refused[] = sprintf("%s: its pattern %s doesn't compile: %s", $at, $pattern, $fault);
                    } elseif ($matched !== 1) {
                        $refused[] = sprintf('%s is %s, not of the pattern %s', $at, self::quote($value), $pattern);
                    }
                    break;
                case 'minimum':
                case 'maximum':
                    if (!self::isNumber($value) || !self::isNumber($rule)) {
                        break;
                    }
                    if (($keyword === 'minimum' && $value < $rule) || ($keyword === 'maximum' && $value > $rule)) {
                        $refused[] = sprintf('%s is %s, past its %s of %s', $at, self::show($value), $keyword, self::show($rule));
                    }
                    break;
                case 'minItems':
                case 'maxItems':
                    if (!is_array($value) || !self::isNumber($rule)) {
                        break;
                    }
                    $count = count($value);
                    if (($keyword === 'minItems' && $count < $rule) || ($keyword === 'maxItems' && $count > $rule)) {
                        $refused[] = sprintf('%s has %d items, past its %s of %s', $at, $count, $keyword, self::show($rule));
                    }
                    break;
                case 'items':
                    if (!is_array($value) || !$rule instanceof stdClass) {
                        break;
                    }
                    foreach ($value as $index => $item) {
                        array_push($refused, ...$this->check($rule, $item, sprintf('%s[%d]', $at, $index)));
                    }
                    break;
                case 'properties':
                    if (!$value instanceof stdClass || !$rule instanceof stdClass) {
                        break;
                    }
                    foreach (self::names($rule) as $name) {
                        $property = $rule->{$name};
                        if (property_exists($value, $name) && $property instanceof stdClass) {
                            array_push($refused, ...$this->check($property, $value->{$name}, "$at.$name"));
                        }
                    }
                    break;
                case 'required':
                    if (!$value instanceof stdClass || !is_array($rule)) {
                        break;
                    }
                    foreach ($rule as $name) {
                        if (!property_exists($value, self::show($name))) {
                            $refused[] = sprintf('%s.%s is missing', $at, self::show($name));
                        }
                    }
                    break;
                case 'additionalProperties':
                    if (!$value instanceof stdClass) {
                        break;
                    }
                    $properties = $schema->properties ?? null;
                    foreach (self::names($value) as $name) {
                        if ($properties instanceof stdClass && property_exists($properties, $name)) {
                            continue;
                        }
                        if ($rule === false) {
                            $refused[] = sprintf('%s.%s is no member it takes', $at, $name);
                        } elseif ($rule instanceof stdClass) {
                            array_push($refused, ...$this->check($rule, $value->{$name}, "$at.$name"));
                        }
                    }
                    break;
                case 'propertyNames':
                    if (!$value instanceof stdClass || !$rule instanceof stdClass) {
                        break;
                    }
                    foreach (self::names($value) as $name) {
                        array_push($refused, ...$this->check($rule, $name, "$at name $name"));
                    }
                    break;
                case 'anyOf':
                    $fits = false;
                    foreach (is_array($rule) ? $rule : [] as $member) {
                        if ($member instanceof stdClass && $this->check($member, $value, $at) === []) {
                            $fits = true;
                            break;
                        }
                    }
                    if (!$fits) {
                        $refused[] = "$at fits none of its anyOf";
                    }
                    break;
                default:
                    if (!in_array($keyword, self::ANNOTATIONS, true)) {
                        $refused[] = sprintf('%s: the contract reads no %s, which its schema gives', $at, $keyword);
                    }
            }
        }
        return $refused;
    }

    /**
     * The operation the request is, by its method and its path on the document's server, with the
     * values of its path's parameters; null where it is none.
     *
     * @return array{Operation, array<string, string>}|null
     */
    private function find(RequestInterface $request): ?array
    {
        $uri = $request->getUri();
        if ($uri->getScheme() !== $this->server->getScheme()
            || $uri->getHost() !== $this->server->getHost()
            || $uri->getPort() !== $this->server->getPort()
        ) {
            return null;
        }
        foreach ($this->operations as $operation) {
            if ($operation->method !== $request->getMethod() || preg_match($operation->pattern, $uri->getPath(), $match) !== 1) {
                continue;
            }
            $values = [];
            foreach ($operation->names as $index => $name) {
                $values[$name] = rawurldecode($match[$index + 1]);
            }
            return [$operation, $values];
        }
        return null;
    }

    private function operationAt(string $route, string $method, stdClass $item, stdClass $entry): Operation
    {
        $id = is_string($entry->operationId ?? null) ? $entry->operationId : '';

        // The path's parameters and the operation's, one for each place and name, the operation's in
        // place of the path's.
        $declared = [];
        foreach ([$item, $entry] as $owner) {
            $list = $owner->parameters ?? null;
            foreach (is_array($list) ? $list : [] as $value) {
                try {
                    $found = $this->resolve($value);
                } catch (RuntimeException $e) {
                    throw new RuntimeException("$id: {$e->getMessage()}", 0, $e);
                }
                $parameter = [
                    'name' => is_string($found->name ?? null) ? $found->name : '',
                    'in' => is_string($found->in ?? null) ? $found->in : '',
                    'required' => ($found->required ?? false) === true,
                    'schema' => ($found->schema ?? null) instanceof stdClass ? $found->schema : new stdClass(),
                ];
                $declared[$parameter['in'] . ':' . strtolower($parameter['name'])] = $parameter;
            }
        }

        try {
            $body = $this->bodySchema($entry);
        } catch (RuntimeException $e) {
            throw new RuntimeException("$id: {$e->getMessage()}", 0, $e);
        }

        $responses = ($entry->responses ?? null) instanceof stdClass ? $entry->responses : new stdClass();
        $success = 0;
        foreach (self::names($responses) as $status) {
            if (preg_match('/^[0-9]+$/D', $status) === 1 && (int) $status >= 200 && (int) $status < 300) {
                $success = (int) $status;
                break;
            }
        }

        // The path on the document's server, each {name} in it a segment of its own.
        preg_match_all(self::PATH_PARAMETER, $route, $matches);
        $serverPath = $this->server->getPath();
        if (str_ends_with($serverPath, '/')) {
            $serverPath = substr($serverPath, 0, -1);
        }
        $parts = preg_split(self::PATH_PARAMETER, $serverPath . $route);
        $quoted = array_map(static fn (string $part): string => preg_quote($part, '#'), $parts === false ? [] : $parts);
        $pattern = '#^' . implode('([^/]+)', $quoted) . '$#D';

        return new Operation(
            $id,
            strtoupper($method),
            $route,
            $success,
            array_values($declared),
            $body,
            $pattern,
            $matches[1],
            $responses,
        );
    }

    /** The schema of an operation's JSON body, or null where it takes none. */
    private function bodySchema(stdClass $entry): ?stdClass
    {
        if (!property_exists($entry, 'requestBody')) {
            return null;
        }
        $body = $this->resolve($entry->requestBody);
        $content = $body->content ?? null;
        $media = $content instanceof stdClass ? ($content->{'application/json'} ?? null) : null;
        if (!$media instanceof stdClass) {
            throw new RuntimeException('its body is not JSON');
        }
        $schema = $media->schema ?? null;
        return $schema instanceof stdClass ? $schema : null;
    }

    /** The object the $ref a value holds names in the document, through each $ref it holds; the value where it holds none. */
    private function resolve(mixed $value): stdClass
    {
        $object = $value instanceof stdClass ? $value : new stdClass();
        while (is_string($object->{'$ref'} ?? null)) {
            $object = $this->pointer($object->{'$ref'});
        }
        return $object;
    }

    /** The object a local reference, #/components/schemas/Fax, names in the document. */
    private function pointer(string $ref): stdClass
    {
        if (!str_starts_with($ref, '#/')) {
            throw new RuntimeException("$ref is no reference into the document");
        }
        $value = $this->raw;
        foreach (explode('/', substr($ref, 2)) as $part) {
            if (!$value instanceof stdClass) {
                throw new RuntimeException("$ref names nothing in the document");
            }
            $value = $value->{str_replace(['~1', '~0'], ['/', '~'], $part)} ?? null;
        }
        if (!$value instanceof stdClass) {
            throw new RuntimeException("$ref names nothing in the document");
        }
        return $value;
    }

    /**
     * Checks a parameter's text, read as its schema's type, against the schema.
     *
     * @return list<string>
     */
    private function checkText(stdClass $schema, string $text, string $at): array
    {
        try {
            $target = $this->resolve($schema);
        } catch (RuntimeException $e) {
            return ["$at: {$e->getMessage()}"];
        }
        if (($target->type ?? null) === 'integer') {
            if (preg_match('/^[+-]?[0-9]+$/D', $text) !== 1) {
                return [sprintf('%s is %s, no integer', $at, self::quote($text))];
            }
            return $this->check($schema, (int) $text, $at);
        }
        return $this->check($schema, $text, $at);
    }

    private static function noOperation(RequestInterface $request): string
    {
        return sprintf('%s %s is no operation of the document', $request->getMethod(), (string) $request->getUri());
    }

    /**
     * A query's parameters, each name with the values it is given, in order, read as a form is: a +
     * is a space, and each name and value is percent-decoded.
     *
     * @return array<string, list<string>>
     */
    private static function query(string $query): array
    {
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $sides = explode('=', $pair, 2);
            $parameters[urldecode($sides[0])][] = urldecode($sides[1] ?? '');
        }
        return $parameters;
    }

    /** A pattern of the document as PCRE reads it: Unicode text, its $ the text's very end alone, as ECMAScript's is. */
    private static function delimited(string $pattern): string
    {
        $body = '';
        $length = strlen($pattern);
        for ($at = 0; $at < $length; $at++) {
            if ($pattern[$at] === '\\' && $at + 1 < $length) {
                $body .= $pattern[$at] . $pattern[$at + 1];
                $at++;
            } elseif ($pattern[$at] === '/') {
                $body .= '\/';
            } else {
                $body .= $pattern[$at];
            }
        }
        return '/' . $body . '/uD';
    }

    /** Whether the value is of the type, or one of the types, a schema's type names. */
    private static function hasType(mixed $rule, mixed $value): bool
    {
        if (is_string($rule)) {
            return self::isType($rule, $value);
        }
        foreach (is_array($rule) ? $rule : [] as $name) {
            if (is_string($name) && self::isType($name, $value)) {
                return true;
            }
        }
        return false;
    }

    private static function isType(string $name, mixed $value): bool
    {
        return match ($name) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'number' => self::isNumber($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'array' => is_array($value),
            'object' => $value instanceof stdClass,
            default => false,
        };
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private static function typeOf(mixed $value): string
    {
        foreach (['null', 'boolean', 'integer', 'number', 'string', 'array', 'object'] as $name) {
            if (self::isType($name, $value)) {
                return $name;
            }
        }
        return get_debug_type($value);
    }

    private static function inList(mixed $rule, mixed $value): bool
    {
        foreach (is_array($rule) ? $rule : [] as $allowed) {
            if (self::same($allowed, $value)) {
                return true;
            }
        }
        return false;
    }

    /** Whether two JSON values are the same, a number the same whether it is read as whole or not. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $index => $item) {
                if (!array_key_exists($index, $b) || !self::same($item, $b[$index])) {
                    return false;
                }
            }
            return true;
        }
        if ($a instanceof stdClass && $b instanceof stdClass) {
            $names = self::names($a);
            if ($names !== self::names($b)) {
                return false;
            }
            foreach ($names as $name) {
                if (!self::same($a->{$name}, $b->{$name})) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    /**
     * An object's member names, sorted as bytes.
     *
     * @return list<string>
     */
    private static function names(stdClass $object): array
    {
        $names = array_map('strval', array_keys(get_object_vars($object)));
        sort($names, SORT_STRING);
        return $names;
    }

    /** A value as a refusal gives it: text as it is, a list in brackets, an object as a map of its members. */
    private static function show(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return '[' . implode(' ', array_map(self::show(...), $value)) . ']';
        }
        if ($value instanceof stdClass) {
            $members = array_map(static fn (string $name): string => $name . ':' . self::show($value->{$name}), self::names($value));
            return 'map[' . implode(' ', $members) . ']';
        }
        return get_debug_type($value);
    }

    /**
     * Each text quoted, as a refusal gives a header's values: ["2026-11-03" "2026-11-03"].
     *
     * @param list<string> $texts
     */
    private static function quoted(array $texts): string
    {
        return '[' . implode(' ', array_map(self::quote(...), $texts)) . ']';
    }

    /** A text quoted, each character JSON escapes escaped. */
    private static function quote(string $text): string
    {
        return json_encode($text, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
