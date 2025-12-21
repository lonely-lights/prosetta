<?php

use LonelyLights\Prosetta\Services\MarkdownSanitizer;

describe('MarkdownSanitizer', function () {
    beforeEach(function () {
        $this->sanitizer = new MarkdownSanitizer();
    });

    it('sanitizes basic HTML', function () {
        $input = '<p>Hello World</p>';
        $result = $this->sanitizer->sanitize($input);

        expect($result)->toContain('Hello World');
    });

    it('removes script tags', function () {
        $input = '<script>alert("XSS")</script>Hello';
        $result = $this->sanitizer->sanitize($input);

        expect($result)->not->toContain('<script>');
        expect($result)->not->toContain('alert');
    });

    it('removes onclick handlers', function () {
        $input = '<div onclick="alert(1)">Click me</div>';
        $result = $this->sanitizer->sanitize($input);

        expect($result)->not->toContain('onclick');
    });

    it('preserves safe markdown-like content', function () {
        $input = 'This is **bold** and *italic*';
        $result = $this->sanitizer->sanitize($input);

        expect($result)->toBe('This is **bold** and *italic*');
    });

    it('handles empty strings', function () {
        $result = $this->sanitizer->sanitize('');

        expect($result)->toBe('');
    });
});
