const assert = require('node:assert/strict');
const test = require('node:test');

const {
  buildContractMeta,
  buildIlasProviderMeta,
} = require('../../lib/ilas-live-shared');

const SITE = 'https://idaholegalaid.org';
const OPTIONS = { providerMode: 'live_api', conversationId: 'conv-intent-001' };

test('contract_meta.intent_selected reads the public meta.intent envelope on hosted targets', () => {
  const payload = {
    message: 'With an urgent deadline, please call our Legal Advice Line right away.',
    type: 'escalation',
    reason_code: 'high_risk_high_risk_deadline',
    meta: {
      intent: { selected: 'high_risk', confidence: 1, source: 'pre_routing_gate_hard_route' },
      generation: { provider: 'cohere', used: false, reason: 'high_risk_high_risk_deadline' },
    },
  };

  const providerMeta = buildIlasProviderMeta(payload, SITE, OPTIONS);
  assert.equal(providerMeta.route.intent, 'high_risk');
  assert.equal(providerMeta.route.intent_confidence, 1);
  assert.equal(providerMeta.route.source, 'pre_routing_gate_hard_route');

  const contractMeta = buildContractMeta(payload, SITE, OPTIONS);
  assert.equal(contractMeta.intent_selected, 'high_risk');
  assert.equal(contractMeta.route_source, 'pre_routing_gate_hard_route');
});

test('debug envelope still wins over the public meta.intent label', () => {
  const payload = {
    message: 'Idaho Legal Aid Services offers three Apply for Help options.',
    type: 'apply_cta',
    reason_code: 'direct_navigation_apply',
    meta: { intent: { selected: 'apply_for_help', confidence: 0.9, source: 'gate_hard_route' } },
    _debug: { intent_selected: 'apply', intent_confidence: 0.95, intent_source: 'rule_based' },
  };

  const contractMeta = buildContractMeta(payload, SITE, OPTIONS);
  assert.equal(contractMeta.intent_selected, 'apply');
  assert.equal(contractMeta.route_source, 'rule_based');
});

test('intent_selected stays null when neither envelope carries a label', () => {
  const contractMeta = buildContractMeta({ message: 'hello', type: 'faq' }, SITE, OPTIONS);
  assert.equal(contractMeta.intent_selected, null);
  assert.equal(contractMeta.route_source, null);
});
