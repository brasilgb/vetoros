import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { isValidWhatsappPhone } from '../../resources/js/Utils/mask.tsx';

describe('isValidWhatsappPhone', () => {
    test('aceita número com DDD e prefixo 55 ausente', () => {
        assert.equal(isValidWhatsappPhone('(51) 99999-9999'), true);
    });

    test('aceita número já com prefixo 55', () => {
        assert.equal(isValidWhatsappPhone('5551999999999'), true);
    });

    test('rejeita vazio, nulo e sem dígitos', () => {
        assert.equal(isValidWhatsappPhone(''), false);
        assert.equal(isValidWhatsappPhone(null), false);
        assert.equal(isValidWhatsappPhone('---'), false);
    });

    test('rejeita número curto demais para WhatsApp', () => {
        assert.equal(isValidWhatsappPhone('99999999'), false);
    });
});
