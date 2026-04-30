(function () {
  const config = window.PickaxeEmbedSSOConfig;
  if (!config) return;

  const target = document.querySelector(config.target);
  if (!target) return;

  function renderMessage(message) {
    target.textContent = message;
  }

  async function getJwt() {
    const response = await fetch(config.tokenUrl, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-WP-Nonce': config.nonce,
      },
    });

    if (!response.ok) {
      throw new Error(`Pickaxe SSO token request failed: ${response.status}`);
    }

    const payload = await response.json();
    return payload.token;
  }

  if (config.loggedIn) {
    window.PickaxeConfig = window.PickaxeConfig || {};
    window.PickaxeConfig.sso = window.PickaxeConfig.sso || {};
    window.PickaxeConfig.sso.getJwt = getJwt;
  }

  function mountEmbed() {
    if (!window.PickaxeEmbed || typeof window.PickaxeEmbed.mount !== 'function') {
      renderMessage('Pickaxe embed loader did not expose PickaxeEmbed.mount.');
      return;
    }

    const mountConfig = {
      target: config.target,
      serviceOrigin: config.serviceOrigin,
    };

    if (config.loggedIn) {
      mountConfig.getToken = getJwt;
    }

    window.PickaxeEmbed.mount(mountConfig);
  }

  if (!config.scriptUrl) {
    renderMessage('Pickaxe embed script URL is not configured.');
    return;
  }

  const script = document.createElement('script');
  script.src = config.scriptUrl;
  script.async = true;
  script.onload = mountEmbed;
  script.onerror = function () {
    renderMessage('Unable to load the Pickaxe embed script.');
  };
  document.head.appendChild(script);
})();
