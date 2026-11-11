import { describe, expect, it } from 'vitest';
import { readJsonResponse } from './http';

describe('JSON HTTP responses', () => {
    it('parses JSON responses', async () => {
        const response = new Response('{"ok":true}', {
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
        });

        await expect(readJsonResponse(response)).resolves.toEqual({ ok: true });
    });

    it('explains when a route returned HTML instead of JSON', async () => {
        const response = new Response('<!doctype html>', {
            status: 200,
            headers: { 'Content-Type': 'text/html; charset=UTF-8' },
        });

        await expect(readJsonResponse(response)).rejects.toThrow('Check the route URL and sign-in state.');
    });
});
