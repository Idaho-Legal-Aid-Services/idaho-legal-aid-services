function appendRoutePath(baseUrl, routePath = '/assistant/api/message') {
  const normalizedBase = String(baseUrl || '').trim().replace(/\/+$/, '');
  const normalizedRoute = String(routePath || '/assistant/api/message').startsWith('/')
    ? String(routePath || '/assistant/api/message')
    : `/${String(routePath || '/assistant/api/message')}`;
  return `${normalizedBase}${normalizedRoute}`;
}

function inferPantheonEnvFromHost(host = '') {
  const normalizedHost = String(host || '').trim().toLowerCase();
  const match = normalizedHost.match(/^(dev|test|live)-[a-z0-9-]+\.pantheonsite\.io$/);
  return match ? match[1] : '';
}

function classifyTargetUrl(assistantUrl, ddevPrimaryUrl = '') {
  const parsedAssistantUrl = new URL(assistantUrl);
  const ddevHost = ddevPrimaryUrl ? new URL(ddevPrimaryUrl).hostname : '';
  const assistantHost = parsedAssistantUrl.hostname;
  const targetKind =
    assistantHost.endsWith('.ddev.site') || (ddevHost !== '' && assistantHost === ddevHost)
      ? 'ddev'
      : 'remote';

  return {
    assistantUrl: parsedAssistantUrl.toString(),
    host: assistantHost,
    targetKind,
    pantheonEnv: inferPantheonEnvFromHost(assistantHost),
  };
}

function validateTargetEnv(assistantUrl, requestedEnv, ddevPrimaryUrl = '') {
  const target = classifyTargetUrl(assistantUrl, ddevPrimaryUrl);
  const normalizedRequestedEnv = String(requestedEnv || '').trim().toLowerCase();

  let resolvedTargetEnv = '';
  let targetValidationStatus = 'not_applicable';

  if (target.targetKind === 'remote' && target.pantheonEnv !== '') {
    resolvedTargetEnv = target.pantheonEnv;
    targetValidationStatus =
      normalizedRequestedEnv === '' || normalizedRequestedEnv === resolvedTargetEnv
        ? 'matched'
        : 'target_env_mismatch';
  }

  return {
    ...target,
    requestedEnv: normalizedRequestedEnv,
    resolvedTargetEnv,
    targetValidationStatus,
  };
}

module.exports = {
  appendRoutePath,
  classifyTargetUrl,
  inferPantheonEnvFromHost,
  validateTargetEnv,
};
