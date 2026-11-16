import test from 'node:test';
import assert from 'node:assert/strict';
import { imageFileError, MAX_IMAGE_BYTES, visualSearchResultMessage } from '../../resources/js/buyer/visual-search.js';

test('supported uploads include the exact 5 MB boundary', () => {
    for (const type of ['image/jpeg', 'image/png', 'image/webp']) {
        assert.equal(imageFileError({ type, size: MAX_IMAGE_BYTES }), null);
    }
});

test('oversized, missing, SVG and disguised files receive useful feedback', () => {
    assert.match(imageFileError(null), /Choose/);
    assert.match(imageFileError({ type: 'image/png', size: MAX_IMAGE_BYTES + 1 }), /5 MB/);
    for (const type of ['image/svg+xml', 'text/plain', 'application/octet-stream', '']) {
        assert.match(imageFileError({ type, size: 20 }), /JPEG, PNG and WebP/);
    }
});

test('empty results offer browsing without describing category options as image matches', () => {
    assert.match(visualSearchResultMessage(0), /No sufficiently similar catalog photos/);
    assert.match(visualSearchResultMessage(0), /browse products by category/);
    assert.doesNotMatch(visualSearchResultMessage(0), /0 visual suggestions|semantic|exact match/i);
});

test('successful result announcements distinguish one suggestion from several', () => {
    assert.equal(visualSearchResultMessage(1), '1 visual suggestion found.');
    assert.equal(visualSearchResultMessage(3), '3 visual suggestions found.');
});

test('category recommendations are never announced as visual matches', () => {
    assert.equal(visualSearchResultMessage(3, 3), '3 category recommendations found.');
    assert.equal(visualSearchResultMessage(3, 2), '1 photo comparison and 2 category recommendations found.');
    assert.equal(visualSearchResultMessage(1, 1), '1 category recommendation found.');
});
