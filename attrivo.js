(function (window) {
  const Attrivo = {};

  let CONFIG = {
    appId: null,
    apiKey: null,
    autoTrack: true,
    externalAppId: null,
    externalId: null
  };

  /** ---------------- Utils ---------------- */

  function getCookie(name) {
    return document.cookie
      .split("; ")
      .find(row => row.startsWith(name + "="))
      ?.split("=")[1];
  }

  function setCookie(name, value, maxAgeSeconds) {
    document.cookie = `${name}=${value}; max-age=${maxAgeSeconds}; path=/; SameSite=Lax`;
  }

  const VISITOR_ID_TTL = 30 * 24 * 60 * 60 * 1000;

  function getOrCreateVisitorId() {
    const now = Date.now();

    let visitorData = JSON.parse(
      localStorage.getItem("attr_visitor_data") || "{}"
    );

    if (
      !visitorData.visitorId ||
      now - visitorData.createdAt > VISITOR_ID_TTL
    ) {
      visitorData = {
        visitorId: crypto.randomUUID(),
        createdAt: now
      };

      localStorage.setItem(
        "attr_visitor_data",
        JSON.stringify(visitorData)
      );
    }

    return visitorData.visitorId;
  }

  function getEncodedQueryReferrer() {
    const search = window.location.search;

    if (!search || search === "?") return "";

    try {
      return decodeURIComponent(search.substring(1));
    } catch {
      return "";
    }
  }

  function storeReferrer() {
    const referrer = getEncodedQueryReferrer();

    if (referrer) {
      localStorage.setItem("attr_referrer", referrer);
      setCookie("attr_referrer", referrer, 30 * 24 * 3600);
    }
  }

  const VISITOR_TTL = 24 * 60 * 60 * 1000; // 24 hours

  function shouldFireVisitor() {
    const last = localStorage.getItem("attr_visitor_last_tracked");

    if (!last) return true;

    const now = Date.now();
    return now - parseInt(last, 10) > VISITOR_TTL;
  }

  function markVisitorFired() {
    localStorage.setItem("attr_visitor_last_tracked", Date.now());
  }

  function getOrCreateSessionId() {
    let sessionId = sessionStorage.getItem("attr_session_id");
    if (!sessionId) {
      sessionId = crypto.randomUUID();
      sessionStorage.setItem("attr_session_id", sessionId);
    }
    return sessionId;
  }

  async function generateHmacSHA256(message, secret) {
    const enc = new TextEncoder();

    const key = await crypto.subtle.importKey(
      "raw",
      enc.encode(secret),
      { name: "HMAC", hash: "SHA-256" },
      false,
      ["sign"]
    );

    const signature = await crypto.subtle.sign(
      "HMAC",
      key,
      enc.encode(message)
    );

    return Array.from(new Uint8Array(signature))
      .map(b => b.toString(16).padStart(2, "0"))
      .join("");
  }

  async function signPayload(payload) {
    const ENCODED_SECRET = "ZWI3OWExNDg5OWVkZGVlNmYyNzliYjQ3ZTg1ODQ3NDQ3MzM5NDA1ODNhMzdhZjBiNmYzN2Y1OTE5ODhkMDgzZQ==";
    const SECRET = atob(ENCODED_SECRET);

    // Only allowed keys
    const allowedKeys = [
      "attrivo_app_id",
      "event_name",
      "event_time",
      "platform",
      "session_id",
      "ip_address"
    ];

    // Build canonical string using only allowed keys
    const canonical = allowedKeys
      .filter(key => payload[key] !== undefined)
      .map(key => `${key}=${payload[key]}`)
      .join("|");

    const sign = await generateHmacSHA256(canonical, SECRET);

    payload.hmac_sign = sign;

    return payload;
  }

  function getTimezoneOffsetFormatted() {
    const offset = -new Date().getTimezoneOffset();
    const sign = offset >= 0 ? "+" : "-";

    const absOffset = Math.abs(offset);
    const hours = String(Math.floor(absOffset / 60)).padStart(2, "0");
    const minutes = String(absOffset % 60).padStart(2, "0");

    return `${sign}${hours}:${minutes}`;
  }

  function getDeviceInfo() {
    const parser = new UAParser();
    const result = parser.getResult();

    return {
      browser: result.browser.name || "",
      browser_version: result.browser.version || "",
      os: result.os.name || "",
      os_version: result.os.version || "",
      device_type: result.device.type || "desktop"
    };
  }

  /** ---------------- Payload ---------------- */

  async function buildPayload(eventName, eventData = null) {
    const device = getDeviceInfo();

    const encodedReferrer =
      getEncodedQueryReferrer() ||
      localStorage.getItem("attr_referrer") ||
      getCookie("attr_referrer") ||
      "";

    const isOrganic = !encodedReferrer;

    const payload = {
      attrivo_app_id: CONFIG.appId,
      external_app_id: CONFIG.externalAppId || "",
      external_id: CONFIG.externalId || "",
      ip_address: "",
      city: "",
      region: "",
      country: "",
      event_name: eventName,
      event_time: Date.now(),
      click_id: "",
      referrer: isOrganic ? "" : encodedReferrer,
      platform: "web",
      sdk_version: "1.0.0",
      timezone: "",
      locale: navigator.language,
      session_id: getOrCreateVisitorId(),
      visitor_id: getOrCreateVisitorId(),
      timezone_offset: getTimezoneOffsetFormatted(),
      browser: device.browser,
      browser_version: device.browser_version,
      os: device.os,
      os_version: device.os_version,
      device_type: device.device_type,
      user_agent: navigator.userAgent
    };

    if (eventData) {
      payload.event_data = eventData;
    }

    return payload;
  }

  /** ---------------- Send ---------------- */

  async function sendEvent(payload) {
    try {
      payload = await signPayload(payload);

      const res = await fetch("https://stag-api.attrivo.in/api/event/ingest", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "App-Id": CONFIG.appId,
          "Authorization": CONFIG.apiKey
        },
        body: JSON.stringify(payload)
      });

      if (!res.ok) {
        console.warn("❌ Failed:", res.status);
      } else {
        console.log("✅ Event sent");
      }

    } catch (e) {
      console.error("Tracking failed:", e);
    }
  }

  /** ---------------- Public APIs ---------------- */

  Attrivo.init = function (config) {
    if (!config?.appId || !config?.apiKey) {
      console.error("init requires appId & apiKey");
      return;
    }

    CONFIG = { ...CONFIG, ...config };

    // run referrer capture
    storeReferrer();

    // visitor event
    if (shouldFireVisitor()) {
      Attrivo.trackEvent("install");
      markVisitorFired();
    } else {
      console.log("Visitor skipped (TTL not expired)");
    }
  };

  Attrivo.trackEvent = async function (eventName, eventData = null) {
    if (!CONFIG.appId) {
      console.error("SDK not initialized");
      return;
    }

    const payload = await buildPayload(eventName, eventData);

    // Optional: log type
    if (!payload.referrer) {
      console.log("Organic traffic detected");
    }

    sendEvent(payload);
  };

  /** ---------------- Setters ---------------- */

  Attrivo.setExternalAppId = function (externalAppId) {
    CONFIG.externalAppId = externalAppId;
  };

  Attrivo.setExternalId = function (externalId) {
    CONFIG.externalId = externalId;
  };

  /** ---------------- Getters ---------------- */

  Attrivo.getAttrivoSessionId = function () {
    return getOrCreateVisitorId();
  };

  Attrivo.getVisitorId = function () {
    return getOrCreateVisitorId();
  };

  /** ---------------- Smart Link ---------------- */
  //
  // Client-side counterpart to AppsFlyer's OneLink Smart Script: turns a web
  // visitor's incoming URL (UTM params, click ids, etc.) into an outgoing
  // attribution link, so a click on a "Get the app" button carries the same
  // attribution context into the app store and then into the app itself.
  //
  // Deliberately built against the EXACT parameter surface the rest of the
  // system already understands — the same query params LinkController's
  // GET /link/{clientAppId} route accepts, and the same ones the dashboard's
  // "Attribution Link" page (attrivo-frontend/src/pages/settings/
  // attribution-link.vue) lets a marketer set by hand. A link this module
  // builds and a link a marketer builds in the dashboard are indistinguishable
  // to the backend — same host, same path shape, same query keys.
  //
  // Not implemented here (by design, for this pass): QR code rendering,
  // view-through impression tracking, and a dashboard-configurable mapping
  // UI (marketer builds the field mapping in attrivo-frontend instead of a
  // developer declaring it in code). All three can be layered on top of
  // this same generateSmartLink() surface later without a breaking change.

  // Fixed top-level link fields — always resolved directly from config, not
  // scanned out of the incoming URL, mirroring how the dashboard's link
  // builder treats them (one static value per generated link, not something
  // that varies per visitor click).
  const SMART_LINK_FIXED_KEYS = ["ios", "web", "redirect_url"];

  // Every other parameter LinkController/the dashboard understands today.
  // See attribution-link.vue's `parameterOption` list (the "Add Parameter"
  // table) plus the three core mapped fields (pid, partner, c) for the
  // source of truth this list is kept in sync with.
  const SMART_LINK_MAPPED_KEYS = [
    "pid", "partner", "c",
    "ad_name", "ad_set", "ad_type", "ad_id", "atset_id", "c_id", "channel",
    "click_id", "click_lookback", "gaid", "idfa", "reengagement", "siteid",
    "lookback_window", "deep_link_value",
    "deep_link_sub1", "deep_link_sub2", "deep_link_sub3", "deep_link_sub4", "deep_link_sub5",
    "deep_link_sub6", "deep_link_sub7", "deep_link_sub8", "deep_link_sub9", "deep_link_sub10",
    "af_dp", "custom1", "custom2", "custom3", "custom4", "custom5",
    "cost_model", "cost_value", "cost_currency"
  ];

  // Sensible default incoming-URL aliases for the fields a web visitor's own
  // query string commonly carries (standard UTM naming plus every ad
  // network's own click-id param, all folded into the single generic
  // click_id field the backend already has). A caller can override any of
  // these, or add aliases for fields with no default here, via the
  // `linkParameters` option on generateSmartLink() — anything not listed
  // below simply isn't auto-mapped unless the caller declares its own keys
  // (it can still be set with a plain static defaultValue).
  const SMART_LINK_DEFAULT_KEYS = {
    pid: ["pid", "utm_source"],
    partner: ["partner"],
    c: ["c", "utm_campaign", "campaign"],
    channel: ["channel", "utm_medium"],
    ad_name: ["ad_name", "utm_content"],
    ad_set: ["ad_set", "utm_term"],
    ad_id: ["ad_id"],
    c_id: ["c_id"],
    siteid: ["siteid"],
    // Every major ad network's own click-id query param collapses into the
    // one generic click_id field the backend/dashboard already use.
    click_id: ["click_id", "gclid", "fbclid", "ttclid", "twclid", "sccid"],
    gaid: ["gaid"],
    idfa: ["idfa"],
    deep_link_value: ["deep_link_value"],
    deep_link_sub1: ["deep_link_sub1"],
    deep_link_sub2: ["deep_link_sub2"],
    deep_link_sub3: ["deep_link_sub3"],
    deep_link_sub4: ["deep_link_sub4"],
    deep_link_sub5: ["deep_link_sub5"]
  };

  // pid (media source) is a hard requirement of the backend route — a
  // request with no pid at all won't match GET /link/{clientAppId}. Same
  // convention AppsFlyer itself falls back to (defaulting mediaSource to
  // "any_source") when nothing on the incoming URL identifies a source.
  const SMART_LINK_DEFAULT_PID = "organic_web";

  // "Incoming URL parameters are stored for the duration of the browsing
  // session" — same behavior AppsFlyer documents for Smart Script v2, so a
  // second generateSmartLink() call later in the visit (e.g. after
  // navigating from a landing page to a product page, where the query
  // string is gone) still has whatever was resolved on the first page.
  const SMART_LINK_SESSION_KEY = "attr_smart_link_params";

  function getIncomingParams() {
    const params = {};
    try {
      new URLSearchParams(window.location.search).forEach((value, key) => {
        params[key] = value;
      });
    } catch (e) {
      // malformed query string - fall through with whatever we managed to parse
    }
    return params;
  }

  function getStoredSmartLinkParams() {
    try {
      return JSON.parse(sessionStorage.getItem(SMART_LINK_SESSION_KEY) || "{}");
    } catch (e) {
      return {};
    }
  }

  function storeSmartLinkParams(resolved) {
    try {
      const existing = getStoredSmartLinkParams();
      sessionStorage.setItem(
        SMART_LINK_SESSION_KEY,
        JSON.stringify(Object.assign({}, existing, resolved))
      );
    } catch (e) {
      // storage unavailable (private mode / quota) - same fail-silent policy as the rest of the SDK
    }
  }

  function matchesSkipList(value, skipList) {
    if (!Array.isArray(skipList) || skipList.length === 0) return false;
    if (!value) return false;
    return skipList.some(entry => entry && value.indexOf(entry) !== -1);
  }

  // Resolves ONE field the same way OneLink Smart Script resolves an
  // afParameters entry: scan `keys` left-to-right against the incoming URL,
  // falling back to whatever was already resolved earlier this session,
  // stop at the first match, apply `overrideValues` if the matched value is
  // in that map, else fall back to `defaultValue`.
  function resolveSmartLinkField(fieldConfig, incomingParams, sessionParams) {
    const keys = (fieldConfig && fieldConfig.keys) || [];
    let matched;

    for (let i = 0; i < keys.length && matched === undefined; i++) {
      if (incomingParams[keys[i]]) matched = incomingParams[keys[i]];
    }

    if (matched === undefined) {
      for (let i = 0; i < keys.length && matched === undefined; i++) {
        if (sessionParams[keys[i]]) matched = sessionParams[keys[i]];
      }
    }

    if (matched === undefined) return fieldConfig ? fieldConfig.defaultValue : undefined;

    if (fieldConfig.overrideValues && Object.prototype.hasOwnProperty.call(fieldConfig.overrideValues, matched)) {
      return fieldConfig.overrideValues[matched];
    }

    return matched;
  }

  // Percent-encodes a single query value. Mirrors LinkController and the
  // dashboard's link builder: every value is encoded individually before
  // being joined with "&", so a value that itself contains "&"/"=" (most
  // commonly deep_link_value or af_dp carrying a full URL with its own query
  // string) can't be misread as extra top-level parameters once the backend
  // or the OS decodes it.
  function encodeLinkValue(value) {
    return encodeURIComponent(String(value));
  }

  Attrivo.SmartLink = {};

  /**
   * Builds an outgoing Attrivo attribution link from the current page's
   * incoming URL, the same way AF_SMART_SCRIPT.generateOneLinkURL() builds
   * an outgoing OneLink URL. Pure string-building — safe to call on every
   * page render / button mount, never touches the network.
   *
   * @param {Object} config
   * @param {string} config.clientAppId - required. Same clientAppId used when
   *   creating this app's attribution link in the dashboard (package name /
   *   bundle id, or the numeric App Store id convention some iOS apps use).
   * @param {string} [config.baseUrl] - defaults to "https://stag-api.attrivo.in/link".
   * @param {string} [config.iosAppId] - Apple numeric App Store id -> "ios" param.
   * @param {string} [config.webFallback] - desktop / no-store fallback -> "web" param.
   * @param {string} [config.redirectUrl] - overrides the final redirect entirely -> "redirect_url".
   * @param {Object} [config.linkParameters] - per-field { keys, overrideValues, defaultValue }
   *   overrides/additions, keyed by the SAME parameter names the backend/dashboard use
   *   (pid, partner, c, channel, click_id, deep_link_value, af_dp, custom1-5, ...).
   * @param {string[]} [config.referrerSkipList] - if document.referrer contains any of these, return null.
   * @param {string[]} [config.urlSkipList] - if the current page URL contains any of these, return null.
   * @returns {{clickURL: string, params: Object}|null}
   */
  Attrivo.SmartLink.generateSmartLink = function (config) {
    config = config || {};

    if (!config.clientAppId) {
      console.error("SmartLink.generateSmartLink requires clientAppId");
      return null;
    }

    if (matchesSkipList(document.referrer, config.referrerSkipList)) return null;
    if (matchesSkipList(window.location.href, config.urlSkipList)) return null;

    const incomingParams = getIncomingParams();
    const sessionParams = getStoredSmartLinkParams();
    const fieldConfigs = config.linkParameters || {};
    const resolved = {};

    SMART_LINK_MAPPED_KEYS.forEach(function (fieldKey) {
      let fieldConfig = fieldConfigs[fieldKey];

      if (!fieldConfig) {
        const defaultKeys = SMART_LINK_DEFAULT_KEYS[fieldKey];
        if (!defaultKeys) return;
        fieldConfig = { keys: defaultKeys };
      }

      const value = resolveSmartLinkField(fieldConfig, incomingParams, sessionParams);
      if (value !== undefined && value !== "") resolved[fieldKey] = value;
    });

    // Guarantee a non-empty pid regardless of what the caller configured -
    // the backend route requires one to match at all.
    if (!resolved.pid) resolved.pid = SMART_LINK_DEFAULT_PID;

    // Persist whatever was resolved (plus the raw incoming params) for the
    // rest of the browsing session, so a later page with no query string of
    // its own can still build the same link.
    storeSmartLinkParams(Object.assign({}, incomingParams, resolved));

    const baseUrl = (config.baseUrl || "https://stag-api.attrivo.in/link").replace(/\/$/, "");
    const parts = [];

    if (resolved.pid) parts.push("pid=" + encodeLinkValue(resolved.pid));
    if (resolved.partner) parts.push("partner=" + encodeLinkValue(resolved.partner));
    if (resolved.c) parts.push("c=" + encodeLinkValue(resolved.c));
    if (config.iosAppId) parts.push("ios=" + encodeLinkValue(config.iosAppId));

    if (config.webFallback) {
      // Kept readable, same as the dashboard's long-link builder - only "&"
      // and "#" are escaped, since those are the only characters that could
      // split the top-level query string.
      parts.push("web=" + String(config.webFallback).replace(/&/g, "%26").replace(/#/g, "%23"));
    }

    if (config.redirectUrl) parts.push("redirect_url=" + encodeLinkValue(config.redirectUrl));

    SMART_LINK_MAPPED_KEYS.forEach(function (fieldKey) {
      if (fieldKey === "pid" || fieldKey === "partner" || fieldKey === "c") return; // already appended above
      if (resolved[fieldKey] === undefined) return;
      parts.push(fieldKey + "=" + encodeLinkValue(resolved[fieldKey]));
    });

    const clickURL = baseUrl + "/" + encodeURIComponent(config.clientAppId) + "?" + parts.join("&");

    return { clickURL, params: resolved };
  };

  // Note: view-through impression tracking (fireImpression) is intentionally
  // not implemented in this pass - out of scope for now, can be layered on
  // top of generateSmartLink() later without a breaking change.

  /** ---------------- Export ---------------- */

  window.Attrivo = Attrivo;

})(window);
