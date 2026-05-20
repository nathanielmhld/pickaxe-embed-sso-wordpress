(function () {
  const configs = Array.isArray(window.PickaxeEmbedSSOConfigs)
    ? window.PickaxeEmbedSSOConfigs
    : window.PickaxeEmbedSSOConfig
      ? [window.PickaxeEmbedSSOConfig]
      : [];
  if (!configs.length) return;

  function renderMessage(target, message) {
    target.textContent = message;
  }

  async function fetchJwtFromUrl(url, config) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-WP-Nonce': config.nonce,
      },
    });

    if (!response.ok) {
      throw new Error(`Pickaxe SSO token request failed: ${response.status}`);
    }

    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
      throw new Error('Pickaxe SSO token request did not return JSON.');
    }

    const payload = await response.json();
    if (!payload.token) {
      throw new Error('Pickaxe SSO token response did not include a token.');
    }

    return payload.token;
  }

  async function getJwt(config) {
    const urls = [config.tokenUrl, config.fallbackTokenUrl].filter(Boolean);
    let lastError;

    for (const url of urls) {
      try {
        return await fetchJwtFromUrl(url, config);
      } catch (error) {
        lastError = error;
      }
    }

    throw lastError || new Error('Pickaxe SSO token URL is not configured.');
  }

  function installScriptSso(config) {
    if (!config.loggedIn) return;

    window.PickaxeConfig = window.PickaxeConfig || {};
    window.PickaxeConfig.sso = window.PickaxeConfig.sso || {};
    window.PickaxeConfig.sso.getJwt = function () {
      return getJwt(config);
    };

    if (config.deploymentId) {
      const deploymentConfig = window.PickaxeConfig[config.deploymentId] || {};
      deploymentConfig.sso = deploymentConfig.sso || {};
      deploymentConfig.sso.getJwt = function () {
        return getJwt(config);
      };
      window.PickaxeConfig[config.deploymentId] = deploymentConfig;
    }
  }

  function mountEmbed(config, target) {
    // The production Pickaxe bundle auto-mounts by scanning for deployment-* IDs.
    // The local mock exposes PickaxeEmbed.mount, so keep this as a compatibility path.
    if (!window.PickaxeEmbed || typeof window.PickaxeEmbed.mount !== 'function') return;

    const mountConfig = {
      target: config.target,
      serviceOrigin: config.serviceOrigin,
    };

    if (config.loggedIn) {
      mountConfig.getToken = function () {
        return getJwt(config);
      };
    }

    window.PickaxeEmbed.mount(mountConfig);
  }

  function installIframeBridge(config) {
    if (!config.loggedIn || !config.iframeOrigin) return;

    window.addEventListener('message', async function (event) {
      const data = event.data || {};
      if (event.origin !== config.iframeOrigin) return;
      if (!event.source || data.type !== 'pickaxe:sso:request') return;
      if (data.deploymentId && data.deploymentId !== config.deploymentId) return;

      try {
        const jwt = await getJwt(config);
        event.source.postMessage(
          {
            type: 'pickaxe:sso:response',
            requestId: data.requestId || null,
            deploymentId: config.deploymentId,
            jwt,
            origin: window.location.origin,
          },
          event.origin,
        );
      } catch (error) {
        event.source.postMessage(
          {
            type: 'pickaxe:sso:error',
            requestId: data.requestId || null,
            deploymentId: config.deploymentId,
            error: error instanceof Error ? error.message : 'Unable to fetch Pickaxe SSO token.',
          },
          event.origin,
        );
      }
    });
  }

  function initializeConfig(config) {
    if (!config || config.__pickaxeEmbedSsoInitialized) return;
    config.__pickaxeEmbedSsoInitialized = true;

    const target = document.querySelector(config.target);
    if (!target) return;

    installScriptSso(config);

    if (config.mode === 'iframe') {
      installIframeBridge(config);
      return;
    }

    if (!config.scriptUrl) {
      renderMessage(target, 'Pickaxe embed script URL is not configured.');
      return;
    }

    const script = document.createElement('script');
    script.src = config.scriptUrl;
    script.async = true;
    script.onload = function () {
      mountEmbed(config, target);
    };
    script.onerror = function () {
      renderMessage(target, 'Unable to load the Pickaxe embed script.');
    };
    document.head.appendChild(script);
  }

  configs.forEach(initializeConfig);
})();
