(() => {
  const proto = globalThis.Element?.prototype;
  if (!proto || proto.__prstudioAttachShadowPatched) return;
  const original = proto.attachShadow;
  if (typeof original !== 'function') return;
  Object.defineProperty(proto, '__prstudioAttachShadowPatched', { value: true, configurable: false, enumerable: false });
  proto.attachShadow = function(init) {
    const root = original.call(this, init);
    queueMicrotask(() => {
      try { this.dispatchEvent(new CustomEvent('__prstudio_shadow_root_attached', { bubbles: true, composed: true })); } catch { /* runtime can deep-scan on demand */ }
    });
    return root;
  };
})();
