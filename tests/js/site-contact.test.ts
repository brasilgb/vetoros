import assert from 'node:assert/strict';
import test from 'node:test';
import { contactLink, formatWhatsappNumber } from '../../resources/js/pages/site/components/site-contact.ts';

const channel = {
    number: '+55 (51) 99893-1325',
    name: 'Atendimento VetorOS',
    message: 'Quero informações',
    publicEnabled: true,
    internalEnabled: true,
    provider: 'whatsapp' as const,
};

test('uses the configured normalized number and default message for all public contacts', () => {
    assert.equal(contactLink(channel), 'https://wa.me/5551998931325?text=Quero%20informa%C3%A7%C3%B5es');
    assert.equal(contactLink(channel, 'Plano Pro'), 'https://wa.me/5551998931325?text=Plano%20Pro');
});

test('does not produce a public contact link when contact is disabled or absent', () => {
    assert.equal(contactLink({ ...channel, publicEnabled: false }), null);
    assert.equal(contactLink({ ...channel, number: null }), null);
    assert.equal(contactLink(undefined), null);
});

test('formats the configured phone for display while preserving its normalized digits for links', () => {
    assert.equal(formatWhatsappNumber('5551998931325'), '+55 (51) 99893-1325');
    assert.equal(formatWhatsappNumber('51998931325'), '(51) 99893-1325');
});
