//#region web/src/client.ts
var e = class extends Error {
	constructor(e, t = 0) {
		super(e), this.code = e, this.status = t, this.name = "CaptchaError";
	}
}, t = /* @__PURE__ */ new Set([
	"text",
	"slider",
	"drag",
	"rotate",
	"click",
	"audio",
	"math",
	"logic",
	"pow"
]);
function n(e) {
	return !!e && typeof e.id == "string" && e.id.length === 43 && t.has(e.type) && Number.isFinite(e.expiresInMs) && e.expiresInMs > 0 && e.expiresInMs <= 9e5 && Number.isFinite(e.minimumDelayMs) && e.minimumDelayMs >= 0 && e.minimumDelayMs <= 1e4 && (!e.work || typeof e.work.seed == "string" && e.work.seed.length === 43 && Number.isInteger(e.work.bits) && e.work.bits >= 1 && e.work.bits <= 20) && (!e.image || /^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(e.image)) && (!e.piece || /^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(e.piece)) && (!e.audio || /^data:audio\/wav;base64,[A-Za-z0-9+/=]+$/.test(e.audio));
}
var r = class {
	constructor(t = "/", n = globalThis.fetch) {
		this.transport = n;
		let r = typeof location > "u" ? "http://localhost/" : location.href;
		if (this.base = new URL(t.endsWith("/") ? t : t + "/", r), !["http:", "https:"].includes(this.base.protocol) || this.base.username || this.base.password || this.base.search || this.base.hash) throw new e("invalid_endpoint");
	}
	async post(t, n, r) {
		let i = new AbortController(), a = () => i.abort();
		r?.addEventListener("abort", a, { once: !0 }), r?.aborted && i.abort();
		let o = setTimeout(a, 15e3);
		try {
			let r = await this.transport.call(globalThis, new URL(t, this.base), {
				method: "POST",
				credentials: "omit",
				redirect: "error",
				cache: "no-store",
				headers: { "Content-Type": "application/json" },
				body: JSON.stringify(n),
				signal: i.signal
			}), a = await r.text();
			if (a.length > 1e6) throw new e("invalid_response", r.status);
			let o;
			try {
				o = JSON.parse(a);
			} catch {
				throw new e("invalid_response", r.status);
			}
			if (!r.ok) throw new e(o?.code ?? "request_failed", r.status);
			return o;
		} catch (t) {
			throw t instanceof e ? t : new e(i.signal.aborted ? "request_cancelled" : "network_error");
		} finally {
			clearTimeout(o), r?.removeEventListener("abort", a);
		}
	}
	async issue(t, r) {
		let i = await this.post("v1/challenges", { sessionToken: t }, r);
		if (!n(i)) throw new e("invalid_response");
		return i;
	}
	async solve(t, n, r, i, a) {
		let o = await this.post("v1/answers", {
			sessionToken: t,
			challengeId: n,
			answer: r,
			workNonce: i
		}, a);
		if (!o || typeof o.token != "string" || !/^[a-f0-9]{64}\.[A-Za-z0-9_-]{43}$/.test(o.token) || !Number.isFinite(o.expiresInMs) || o.expiresInMs <= 0 || o.expiresInMs > 12e4) throw new e("invalid_response");
		return o;
	}
}, i = globalThis, a = i.ShadowRoot && (i.ShadyCSS === void 0 || i.ShadyCSS.nativeShadow) && "adoptedStyleSheets" in Document.prototype && "replace" in CSSStyleSheet.prototype, o = Symbol(), s = /* @__PURE__ */ new WeakMap(), c = class {
	constructor(e, t, n) {
		if (this._$cssResult$ = !0, n !== o) throw Error("CSSResult is not constructable. Use `unsafeCSS` or `css` instead.");
		this.cssText = e, this.t = t;
	}
	get styleSheet() {
		let e = this.o, t = this.t;
		if (a && e === void 0) {
			let n = t !== void 0 && t.length === 1;
			n && (e = s.get(t)), e === void 0 && ((this.o = e = new CSSStyleSheet()).replaceSync(this.cssText), n && s.set(t, e));
		}
		return e;
	}
	toString() {
		return this.cssText;
	}
}, l = (e) => new c(typeof e == "string" ? e : e + "", void 0, o), u = (e, ...t) => new c(e.length === 1 ? e[0] : t.reduce((t, n, r) => t + ((e) => {
	if (!0 === e._$cssResult$) return e.cssText;
	if (typeof e == "number") return e;
	throw Error("Value passed to 'css' function must be a 'css' function result: " + e + ". Use 'unsafeCSS' to pass non-literal values, but take care to ensure page security.");
})(n) + e[r + 1], e[0]), e, o), d = (e, t) => {
	if (a) e.adoptedStyleSheets = t.map((e) => e instanceof CSSStyleSheet ? e : e.styleSheet);
	else for (let n of t) {
		let t = document.createElement("style"), r = i.litNonce;
		r !== void 0 && t.setAttribute("nonce", r), t.textContent = n.cssText, e.appendChild(t);
	}
}, f = a ? (e) => e : (e) => e instanceof CSSStyleSheet ? ((e) => {
	let t = "";
	for (let n of e.cssRules) t += n.cssText;
	return l(t);
})(e) : e, { is: ee, defineProperty: te, getOwnPropertyDescriptor: ne, getOwnPropertyNames: re, getOwnPropertySymbols: ie, getPrototypeOf: ae } = Object, p = globalThis, m = p.trustedTypes, oe = m ? m.emptyScript : "", se = p.reactiveElementPolyfillSupport, h = (e, t) => e, g = {
	toAttribute(e, t) {
		switch (t) {
			case Boolean:
				e = e ? oe : null;
				break;
			case Object:
			case Array: e = e == null ? e : JSON.stringify(e);
		}
		return e;
	},
	fromAttribute(e, t) {
		let n = e;
		switch (t) {
			case Boolean:
				n = e !== null;
				break;
			case Number:
				n = e === null ? null : Number(e);
				break;
			case Object:
			case Array: try {
				n = JSON.parse(e);
			} catch {
				n = null;
			}
		}
		return n;
	}
}, _ = (e, t) => !ee(e, t), v = {
	attribute: !0,
	type: String,
	converter: g,
	reflect: !1,
	useDefault: !1,
	hasChanged: _
};
Symbol.metadata ??= Symbol("metadata"), p.litPropertyMetadata ??= /* @__PURE__ */ new WeakMap();
var y = class extends HTMLElement {
	static addInitializer(e) {
		this._$Ei(), (this.l ??= []).push(e);
	}
	static get observedAttributes() {
		return this.finalize(), this._$Eh && [...this._$Eh.keys()];
	}
	static createProperty(e, t = v) {
		if (t.state && (t.attribute = !1), this._$Ei(), this.prototype.hasOwnProperty(e) && ((t = Object.create(t)).wrapped = !0), this.elementProperties.set(e, t), !t.noAccessor) {
			let n = Symbol(), r = this.getPropertyDescriptor(e, n, t);
			r !== void 0 && te(this.prototype, e, r);
		}
	}
	static getPropertyDescriptor(e, t, n) {
		let { get: r, set: i } = ne(this.prototype, e) ?? {
			get() {
				return this[t];
			},
			set(e) {
				this[t] = e;
			}
		};
		return {
			get: r,
			set(t) {
				let a = r?.call(this);
				i?.call(this, t), this.requestUpdate(e, a, n);
			},
			configurable: !0,
			enumerable: !0
		};
	}
	static getPropertyOptions(e) {
		return this.elementProperties.get(e) ?? v;
	}
	static _$Ei() {
		if (this.hasOwnProperty(h("elementProperties"))) return;
		let e = ae(this);
		e.finalize(), e.l !== void 0 && (this.l = [...e.l]), this.elementProperties = new Map(e.elementProperties);
	}
	static finalize() {
		if (this.hasOwnProperty(h("finalized"))) return;
		if (this.finalized = !0, this._$Ei(), this.hasOwnProperty(h("properties"))) {
			let e = this.properties, t = [...re(e), ...ie(e)];
			for (let n of t) this.createProperty(n, e[n]);
		}
		let e = this[Symbol.metadata];
		if (e !== null) {
			let t = litPropertyMetadata.get(e);
			if (t !== void 0) for (let [e, n] of t) this.elementProperties.set(e, n);
		}
		this._$Eh = /* @__PURE__ */ new Map();
		for (let [e, t] of this.elementProperties) {
			let n = this._$Eu(e, t);
			n !== void 0 && this._$Eh.set(n, e);
		}
		this.elementStyles = this.finalizeStyles(this.styles);
	}
	static finalizeStyles(e) {
		let t = [];
		if (Array.isArray(e)) {
			let n = new Set(e.flat(1 / 0).reverse());
			for (let e of n) t.unshift(f(e));
		} else e !== void 0 && t.push(f(e));
		return t;
	}
	static _$Eu(e, t) {
		let n = t.attribute;
		return !1 === n ? void 0 : typeof n == "string" ? n : typeof e == "string" ? e.toLowerCase() : void 0;
	}
	constructor() {
		super(), this._$Ep = void 0, this.isUpdatePending = !1, this.hasUpdated = !1, this._$Em = null, this._$Ev();
	}
	_$Ev() {
		this._$ES = new Promise((e) => this.enableUpdating = e), this._$AL = /* @__PURE__ */ new Map(), this._$E_(), this.requestUpdate(), this.constructor.l?.forEach((e) => e(this));
	}
	addController(e) {
		(this._$EO ??= /* @__PURE__ */ new Set()).add(e), this.renderRoot !== void 0 && this.isConnected && e.hostConnected?.();
	}
	removeController(e) {
		this._$EO?.delete(e);
	}
	_$E_() {
		let e = /* @__PURE__ */ new Map(), t = this.constructor.elementProperties;
		for (let n of t.keys()) this.hasOwnProperty(n) && (e.set(n, this[n]), delete this[n]);
		e.size > 0 && (this._$Ep = e);
	}
	createRenderRoot() {
		let e = this.shadowRoot ?? this.attachShadow(this.constructor.shadowRootOptions);
		return d(e, this.constructor.elementStyles), e;
	}
	connectedCallback() {
		this.renderRoot ??= this.createRenderRoot(), this.enableUpdating(!0), this._$EO?.forEach((e) => e.hostConnected?.());
	}
	enableUpdating(e) {}
	disconnectedCallback() {
		this._$EO?.forEach((e) => e.hostDisconnected?.());
	}
	attributeChangedCallback(e, t, n) {
		this._$AK(e, n);
	}
	_$ET(e, t) {
		let n = this.constructor.elementProperties.get(e), r = this.constructor._$Eu(e, n);
		if (r !== void 0 && !0 === n.reflect) {
			let i = (n.converter?.toAttribute === void 0 ? g : n.converter).toAttribute(t, n.type);
			this._$Em = e, i == null ? this.removeAttribute(r) : this.setAttribute(r, i), this._$Em = null;
		}
	}
	_$AK(e, t) {
		let n = this.constructor, r = n._$Eh.get(e);
		if (r !== void 0 && this._$Em !== r) {
			let e = n.getPropertyOptions(r), i = typeof e.converter == "function" ? { fromAttribute: e.converter } : e.converter?.fromAttribute === void 0 ? g : e.converter;
			this._$Em = r;
			let a = i.fromAttribute(t, e.type);
			this[r] = a ?? this._$Ej?.get(r) ?? a, this._$Em = null;
		}
	}
	requestUpdate(e, t, n, r = !1, i) {
		if (e !== void 0) {
			let a = this.constructor;
			if (!1 === r && (i = this[e]), n ??= a.getPropertyOptions(e), !((n.hasChanged ?? _)(i, t) || n.useDefault && n.reflect && i === this._$Ej?.get(e) && !this.hasAttribute(a._$Eu(e, n)))) return;
			this.C(e, t, n);
		}
		!1 === this.isUpdatePending && (this._$ES = this._$EP());
	}
	C(e, t, { useDefault: n, reflect: r, wrapped: i }, a) {
		n && !(this._$Ej ??= /* @__PURE__ */ new Map()).has(e) && (this._$Ej.set(e, a ?? t ?? this[e]), !0 !== i || a !== void 0) || (this._$AL.has(e) || (this.hasUpdated || n || (t = void 0), this._$AL.set(e, t)), !0 === r && this._$Em !== e && (this._$Eq ??= /* @__PURE__ */ new Set()).add(e));
	}
	async _$EP() {
		this.isUpdatePending = !0;
		try {
			await this._$ES;
		} catch (e) {
			Promise.reject(e);
		}
		let e = this.scheduleUpdate();
		return e != null && await e, !this.isUpdatePending;
	}
	scheduleUpdate() {
		return this.performUpdate();
	}
	performUpdate() {
		if (!this.isUpdatePending) return;
		if (!this.hasUpdated) {
			if (this.renderRoot ??= this.createRenderRoot(), this._$Ep) {
				for (let [e, t] of this._$Ep) this[e] = t;
				this._$Ep = void 0;
			}
			let e = this.constructor.elementProperties;
			if (e.size > 0) for (let [t, n] of e) {
				let { wrapped: e } = n, r = this[t];
				!0 !== e || this._$AL.has(t) || r === void 0 || this.C(t, void 0, n, r);
			}
		}
		let e = !1, t = this._$AL;
		try {
			e = this.shouldUpdate(t), e ? (this.willUpdate(t), this._$EO?.forEach((e) => e.hostUpdate?.()), this.update(t)) : this._$EM();
		} catch (t) {
			throw e = !1, this._$EM(), t;
		}
		e && this._$AE(t);
	}
	willUpdate(e) {}
	_$AE(e) {
		this._$EO?.forEach((e) => e.hostUpdated?.()), this.hasUpdated || (this.hasUpdated = !0, this.firstUpdated(e)), this.updated(e);
	}
	_$EM() {
		this._$AL = /* @__PURE__ */ new Map(), this.isUpdatePending = !1;
	}
	get updateComplete() {
		return this.getUpdateComplete();
	}
	getUpdateComplete() {
		return this._$ES;
	}
	shouldUpdate(e) {
		return !0;
	}
	update(e) {
		this._$Eq &&= this._$Eq.forEach((e) => this._$ET(e, this[e])), this._$EM();
	}
	updated(e) {}
	firstUpdated(e) {}
};
y.elementStyles = [], y.shadowRootOptions = { mode: "open" }, y[h("elementProperties")] = /* @__PURE__ */ new Map(), y[h("finalized")] = /* @__PURE__ */ new Map(), se?.({ ReactiveElement: y }), (p.reactiveElementVersions ??= []).push("2.1.2");
//#endregion
//#region node_modules/lit-html/lit-html.js
var b = globalThis, x = (e) => e, S = b.trustedTypes, C = S ? S.createPolicy("lit-html", { createHTML: (e) => e }) : void 0, ce = "$lit$", w = `lit$${Math.random().toFixed(9).slice(2)}$`, T = "?" + w, le = `<${T}>`, E = document, D = () => E.createComment(""), O = (e) => e === null || typeof e != "object" && typeof e != "function", k = Array.isArray, ue = (e) => k(e) || typeof e?.[Symbol.iterator] == "function", A = "[ 	\n\f\r]", j = /<(?:(!--|\/[^a-zA-Z])|(\/?[a-zA-Z][^>\s]*)|(\/?$))/g, M = /-->/g, N = />/g, P = RegExp(`>|${A}(?:([^\\s"'>=/]+)(${A}*=${A}*(?:[^ \t\n\f\r"'\`<>=]|("|')|))|$)`, "g"), F = /'/g, I = /"/g, L = /^(?:script|style|textarea|title)$/i, R = ((e) => (t, ...n) => ({
	_$litType$: e,
	strings: t,
	values: n
}))(1), z = Symbol.for("lit-noChange"), B = Symbol.for("lit-nothing"), V = /* @__PURE__ */ new WeakMap(), H = E.createTreeWalker(E, 129);
function U(e, t) {
	if (!k(e) || !e.hasOwnProperty("raw")) throw Error("invalid template strings array");
	return C === void 0 ? t : C.createHTML(t);
}
var de = (e, t) => {
	let n = e.length - 1, r = [], i, a = t === 2 ? "<svg>" : t === 3 ? "<math>" : "", o = j;
	for (let t = 0; t < n; t++) {
		let n = e[t], s, c, l = -1, u = 0;
		for (; u < n.length && (o.lastIndex = u, c = o.exec(n), c !== null);) u = o.lastIndex, o === j ? c[1] === "!--" ? o = M : c[1] === void 0 ? c[2] === void 0 ? c[3] !== void 0 && (o = P) : (L.test(c[2]) && (i = RegExp("</" + c[2], "g")), o = P) : o = N : o === P ? c[0] === ">" ? (o = i ?? j, l = -1) : c[1] === void 0 ? l = -2 : (l = o.lastIndex - c[2].length, s = c[1], o = c[3] === void 0 ? P : c[3] === "\"" ? I : F) : o === I || o === F ? o = P : o === M || o === N ? o = j : (o = P, i = void 0);
		let d = o === P && e[t + 1].startsWith("/>") ? " " : "";
		a += o === j ? n + le : l >= 0 ? (r.push(s), n.slice(0, l) + ce + n.slice(l) + w + d) : n + w + (l === -2 ? t : d);
	}
	return [U(e, a + (e[n] || "<?>") + (t === 2 ? "</svg>" : t === 3 ? "</math>" : "")), r];
}, W = class e {
	constructor({ strings: t, _$litType$: n }, r) {
		let i;
		this.parts = [];
		let a = 0, o = 0, s = t.length - 1, c = this.parts, [l, u] = de(t, n);
		if (this.el = e.createElement(l, r), H.currentNode = this.el.content, n === 2 || n === 3) {
			let e = this.el.content.firstChild;
			e.replaceWith(...e.childNodes);
		}
		for (; (i = H.nextNode()) !== null && c.length < s;) {
			if (i.nodeType === 1) {
				if (i.hasAttributes()) for (let e of i.getAttributeNames()) if (e.endsWith(ce)) {
					let t = u[o++], n = i.getAttribute(e).split(w), r = /([.?@])?(.*)/.exec(t);
					c.push({
						type: 1,
						index: a,
						name: r[2],
						strings: n,
						ctor: r[1] === "." ? pe : r[1] === "?" ? me : r[1] === "@" ? he : q
					}), i.removeAttribute(e);
				} else e.startsWith(w) && (c.push({
					type: 6,
					index: a
				}), i.removeAttribute(e));
				if (L.test(i.tagName)) {
					let e = i.textContent.split(w), t = e.length - 1;
					if (t > 0) {
						i.textContent = S ? S.emptyScript : "";
						for (let n = 0; n < t; n++) i.append(e[n], D()), H.nextNode(), c.push({
							type: 2,
							index: ++a
						});
						i.append(e[t], D());
					}
				}
			} else if (i.nodeType === 8) {
				if (i.data === T) c.push({
					type: 2,
					index: a
				});
				else {
					let e = -1;
					for (; (e = i.data.indexOf(w, e + 1)) !== -1;) c.push({
						type: 7,
						index: a
					}), e += w.length - 1;
				}
			}
			a++;
		}
	}
	static createElement(e, t) {
		let n = E.createElement("template");
		return n.innerHTML = e, n;
	}
};
function G(e, t, n = e, r) {
	if (t === z) return t;
	let i = r === void 0 ? n._$Cl : n._$Co?.[r], a = O(t) ? void 0 : t._$litDirective$;
	return i?.constructor !== a && (i?._$AO?.(!1), a === void 0 ? i = void 0 : (i = new a(e), i._$AT(e, n, r)), r === void 0 ? n._$Cl = i : (n._$Co ??= [])[r] = i), i !== void 0 && (t = G(e, i._$AS(e, t.values), i, r)), t;
}
var fe = class {
	constructor(e, t) {
		this._$AV = [], this._$AN = void 0, this._$AD = e, this._$AM = t;
	}
	get parentNode() {
		return this._$AM.parentNode;
	}
	get _$AU() {
		return this._$AM._$AU;
	}
	u(e) {
		let { el: { content: t }, parts: n } = this._$AD, r = (e?.creationScope ?? E).importNode(t, !0);
		H.currentNode = r;
		let i = H.nextNode(), a = 0, o = 0, s = n[0];
		for (; s !== void 0;) {
			if (a === s.index) {
				let t;
				s.type === 2 ? t = new K(i, i.nextSibling, this, e) : s.type === 1 ? t = new s.ctor(i, s.name, s.strings, this, e) : s.type === 6 && (t = new ge(i, this, e)), this._$AV.push(t), s = n[++o];
			}
			a !== s?.index && (i = H.nextNode(), a++);
		}
		return H.currentNode = E, r;
	}
	p(e) {
		let t = 0;
		for (let n of this._$AV) n !== void 0 && (n.strings === void 0 ? n._$AI(e[t]) : (n._$AI(e, n, t), t += n.strings.length - 2)), t++;
	}
}, K = class e {
	get _$AU() {
		return this._$AM?._$AU ?? this._$Cv;
	}
	constructor(e, t, n, r) {
		this.type = 2, this._$AH = B, this._$AN = void 0, this._$AA = e, this._$AB = t, this._$AM = n, this.options = r, this._$Cv = r?.isConnected ?? !0;
	}
	get parentNode() {
		let e = this._$AA.parentNode, t = this._$AM;
		return t !== void 0 && e?.nodeType === 11 && (e = t.parentNode), e;
	}
	get startNode() {
		return this._$AA;
	}
	get endNode() {
		return this._$AB;
	}
	_$AI(e, t = this) {
		e = G(this, e, t), O(e) ? e === B || e == null || e === "" ? (this._$AH !== B && this._$AR(), this._$AH = B) : e !== this._$AH && e !== z && this._(e) : e._$litType$ === void 0 ? e.nodeType === void 0 ? ue(e) ? this.k(e) : this._(e) : this.T(e) : this.$(e);
	}
	O(e) {
		return this._$AA.parentNode.insertBefore(e, this._$AB);
	}
	T(e) {
		this._$AH !== e && (this._$AR(), this._$AH = this.O(e));
	}
	_(e) {
		this._$AH !== B && O(this._$AH) ? this._$AA.nextSibling.data = e : this.T(E.createTextNode(e)), this._$AH = e;
	}
	$(e) {
		let { values: t, _$litType$: n } = e, r = typeof n == "number" ? this._$AC(e) : (n.el === void 0 && (n.el = W.createElement(U(n.h, n.h[0]), this.options)), n);
		if (this._$AH?._$AD === r) this._$AH.p(t);
		else {
			let e = new fe(r, this), n = e.u(this.options);
			e.p(t), this.T(n), this._$AH = e;
		}
	}
	_$AC(e) {
		let t = V.get(e.strings);
		return t === void 0 && V.set(e.strings, t = new W(e)), t;
	}
	k(t) {
		k(this._$AH) || (this._$AH = [], this._$AR());
		let n = this._$AH, r, i = 0;
		for (let a of t) i === n.length ? n.push(r = new e(this.O(D()), this.O(D()), this, this.options)) : r = n[i], r._$AI(a), i++;
		i < n.length && (this._$AR(r && r._$AB.nextSibling, i), n.length = i);
	}
	_$AR(e = this._$AA.nextSibling, t) {
		for (this._$AP?.(!1, !0, t); e !== this._$AB;) {
			let t = x(e).nextSibling;
			x(e).remove(), e = t;
		}
	}
	setConnected(e) {
		this._$AM === void 0 && (this._$Cv = e, this._$AP?.(e));
	}
}, q = class {
	get tagName() {
		return this.element.tagName;
	}
	get _$AU() {
		return this._$AM._$AU;
	}
	constructor(e, t, n, r, i) {
		this.type = 1, this._$AH = B, this._$AN = void 0, this.element = e, this.name = t, this._$AM = r, this.options = i, n.length > 2 || n[0] !== "" || n[1] !== "" ? (this._$AH = Array(n.length - 1).fill(/* @__PURE__ */ new String()), this.strings = n) : this._$AH = B;
	}
	_$AI(e, t = this, n, r) {
		let i = this.strings, a = !1;
		if (i === void 0) e = G(this, e, t, 0), a = !O(e) || e !== this._$AH && e !== z, a && (this._$AH = e);
		else {
			let r = e, o, s;
			for (e = i[0], o = 0; o < i.length - 1; o++) s = G(this, r[n + o], t, o), s === z && (s = this._$AH[o]), a ||= !O(s) || s !== this._$AH[o], s === B ? e = B : e !== B && (e += (s ?? "") + i[o + 1]), this._$AH[o] = s;
		}
		a && !r && this.j(e);
	}
	j(e) {
		e === B ? this.element.removeAttribute(this.name) : this.element.setAttribute(this.name, e ?? "");
	}
}, pe = class extends q {
	constructor() {
		super(...arguments), this.type = 3;
	}
	j(e) {
		this.element[this.name] = e === B ? void 0 : e;
	}
}, me = class extends q {
	constructor() {
		super(...arguments), this.type = 4;
	}
	j(e) {
		this.element.toggleAttribute(this.name, !!e && e !== B);
	}
}, he = class extends q {
	constructor(e, t, n, r, i) {
		super(e, t, n, r, i), this.type = 5;
	}
	_$AI(e, t = this) {
		if ((e = G(this, e, t, 0) ?? B) === z) return;
		let n = this._$AH, r = e === B && n !== B || e.capture !== n.capture || e.once !== n.once || e.passive !== n.passive, i = e !== B && (n === B || r);
		r && this.element.removeEventListener(this.name, this, n), i && this.element.addEventListener(this.name, this, e), this._$AH = e;
	}
	handleEvent(e) {
		typeof this._$AH == "function" ? this._$AH.call(this.options?.host ?? this.element, e) : this._$AH.handleEvent(e);
	}
}, ge = class {
	constructor(e, t, n) {
		this.element = e, this.type = 6, this._$AN = void 0, this._$AM = t, this.options = n;
	}
	get _$AU() {
		return this._$AM._$AU;
	}
	_$AI(e) {
		G(this, e);
	}
}, _e = b.litHtmlPolyfillSupport;
_e?.(W, K), (b.litHtmlVersions ??= []).push("3.3.3");
var ve = (e, t, n) => {
	let r = n?.renderBefore ?? t, i = r._$litPart$;
	if (i === void 0) {
		let e = n?.renderBefore ?? null;
		r._$litPart$ = i = new K(t.insertBefore(D(), e), e, void 0, n ?? {});
	}
	return i._$AI(e), i;
}, J = globalThis, Y = class extends y {
	constructor() {
		super(...arguments), this.renderOptions = { host: this }, this._$Do = void 0;
	}
	createRenderRoot() {
		let e = super.createRenderRoot();
		return this.renderOptions.renderBefore ??= e.firstChild, e;
	}
	update(e) {
		let t = this.render();
		this.hasUpdated || (this.renderOptions.isConnected = this.isConnected), super.update(e), this._$Do = ve(t, this.renderRoot, this.renderOptions);
	}
	connectedCallback() {
		super.connectedCallback(), this._$Do?.setConnected(!0);
	}
	disconnectedCallback() {
		super.disconnectedCallback(), this._$Do?.setConnected(!1);
	}
	render() {
		return z;
	}
};
Y._$litElement$ = !0, Y.finalized = !0, J.litElementHydrateSupport?.({ LitElement: Y });
var ye = J.litElementPolyfillSupport;
ye?.({ LitElement: Y }), (J.litElementVersions ??= []).push("4.2.2");
//#endregion
//#region web/src/i18n.ts
var be = {
	en: {
		title: "Verify your request",
		subtitle: "Private verification · runs locally",
		start: "Start verification",
		verify: "Verify",
		refresh: "New challenge",
		answer: "Your answer",
		loading: "Preparing your challenge…",
		verifying: "Checking securely…",
		success: "Verified",
		expired: "Verification expired. Start a new session.",
		idle: "Waiting for a verification session.",
		ready: "Complete the challenge below.",
		local: "No external services",
		work: "Preparing a local security check…",
		text: "Enter the characters shown in the image.",
		slider: "Move the piece into the matching cutout.",
		drag: "Move the piece horizontally and vertically into the cutout.",
		rotate: "Rotate the arrow until it points straight up.",
		click: "Select 1, then 2, then 3. Keyboard: arrows move; Enter selects.",
		audio: "Play the audio and enter the five digits spoken in English.",
		pow: "A bounded security check is running on this device.",
		horizontal: "Horizontal position",
		vertical: "Vertical position",
		angle: "Rotation angle",
		clear: "Clear selection",
		required: "Complete verification before submitting.",
		incorrect_answer: "That did not match. Try again; attempts are limited.",
		too_early: "Please wait a moment before verifying.",
		network_error: "Cannot reach the local verification service.",
		rate_limited: "Too many requests. Try again later.",
		session_locked: "Attempt limit reached. Request a new session.",
		challenge_expired: "This challenge expired. Request a new one.",
		work_timeout: "The local check reached its device budget. Try again or use your application’s alternative verification route.",
		error: "Verification could not complete. Request a new session or contact your administrator."
	},
	ar: {
		title: "تحقّق من الطلب",
		subtitle: "تحقّق خاص يعمل محليًا",
		start: "ابدأ التحقّق",
		verify: "تحقّق",
		refresh: "تحدٍّ جديد",
		answer: "الإجابة",
		loading: "جارٍ تجهيز التحدّي…",
		verifying: "جارٍ التحقّق…",
		success: "تم التحقّق",
		expired: "انتهت صلاحية التحقّق. ابدأ جلسة جديدة.",
		idle: "في انتظار جلسة التحقّق.",
		ready: "أكمل التحدّي التالي.",
		local: "بدون خدمات خارجية",
		work: "جارٍ تجهيز فحص أمان محلي…",
		text: "اكتب الحروف والأرقام الظاهرة في الصورة.",
		slider: "حرّك القطعة إلى المكان الناقص.",
		drag: "حرّك القطعة أفقيًا ورأسيًا إلى المكان الناقص.",
		rotate: "دوّر السهم حتى يشير إلى أعلى.",
		click: "اضغط 1 ثم 2 ثم 3. بالكيبورد: الأسهم للتحريك وEnter للاختيار.",
		audio: "شغّل الصوت واكتب الأرقام الخمسة المنطوقة بالإنجليزية.",
		pow: "يجري فحص أمان محدود على هذا الجهاز.",
		horizontal: "الموضع الأفقي",
		vertical: "الموضع الرأسي",
		angle: "زاوية الدوران",
		clear: "مسح الاختيارات",
		required: "أكمل التحقّق قبل الإرسال.",
		incorrect_answer: "الإجابة غير مطابقة. حاول مجددًا؛ عدد المحاولات محدود.",
		too_early: "انتظر قليلًا قبل التحقّق.",
		network_error: "تعذّر الوصول إلى خدمة التحقّق المحلية.",
		rate_limited: "طلبات كثيرة. حاول لاحقًا.",
		session_locked: "وصلت إلى حد المحاولات. اطلب جلسة جديدة.",
		challenge_expired: "انتهت صلاحية التحدّي. اطلب تحدّيًا جديدًا.",
		work_timeout: "وصل الفحص إلى الحد المسموح لموارد الجهاز. أعد المحاولة أو استخدم مسار التحقّق البديل في التطبيق.",
		error: "تعذّر إكمال التحقّق. اطلب جلسة جديدة أو تواصل مع المسؤول."
	}
}, xe = u`
  :host{--jc-accent:#2463eb;--jc-bg:#fff;--jc-fg:#17253c;--jc-muted:#61718b;--jc-border:#dce4f0;display:inline-block;width:100%;max-width:370px;font:14px/1.5 system-ui,sans-serif;color:var(--jc-fg);color-scheme:light}
  :host([theme=dark]){--jc-bg:#121d30;--jc-fg:#edf3ff;--jc-muted:#b3c1d8;--jc-border:#31415b;color-scheme:dark}
  @media(prefers-color-scheme:dark){:host([theme=auto]){--jc-bg:#121d30;--jc-fg:#edf3ff;--jc-muted:#b3c1d8;--jc-border:#31415b;color-scheme:dark}}
  *{box-sizing:border-box}.card{border:1px solid var(--jc-border);border-radius:18px;background:var(--jc-bg);padding:20px;box-shadow:0 8px 32px #071c3b0a}
  header{display:flex;gap:12px;align-items:center;margin-bottom:18px}.mark{width:38px;height:40px;display:grid;place-items:center;border:1px solid var(--jc-border);border-radius:12px;color:var(--jc-accent);font-weight:800;font-size:20px}h2{font-size:16px;line-height:1.3;margin:0}small{color:var(--jc-muted);font-size:11px}.prompt{margin:0 0 12px;font-size:13px}.status{font-size:13px;min-height:20px;margin:12px 0 0;color:var(--jc-muted)}.error{color:#c63847}.success{padding:18px;border:1px solid #2f9f783d;background:#2f9f7812;border-radius:12px;text-align:center;color:#209471;font-weight:700}
  button,input{font:inherit}button{cursor:pointer;border:1px solid var(--jc-border);border-radius:10px;padding:10px 14px;background:var(--jc-bg);color:var(--jc-fg)}button.primary{background:var(--jc-accent);border-color:var(--jc-accent);color:white;font-weight:650;flex:1}button:disabled{opacity:.5;cursor:not-allowed}button:focus-visible,input:focus-visible,.board:focus-visible{outline:3px solid var(--jc-accent);outline-offset:3px}.actions{display:flex;gap:8px;margin-top:16px}.refresh{font-size:12px}
  .board{position:relative;width:100%;max-width:300px;margin:12px auto;overflow:hidden;border-radius:10px;touch-action:none;direction:ltr;background:#e8eff7}.board>img:first-child{display:block;width:100%;height:auto}.piece{position:absolute;cursor:grab;user-select:none;touch-action:none;filter:drop-shadow(0 2px 3px #0008)}.rotation{width:180px;height:180px;margin:12px auto}.rotation img{width:100%;height:100%;display:block}.text-image{width:100%;max-width:300px;display:block;margin:8px auto;border-radius:10px}.point{position:absolute;width:22px;height:22px;border:2px solid white;border-radius:50%;background:var(--jc-accent);color:white;display:grid;place-items:center;font-size:11px;transform:translate(-50%,-50%);pointer-events:none}.cursor{position:absolute;width:14px;height:14px;border:2px solid white;box-shadow:0 0 0 1px #142030;transform:translate(-50%,-50%);pointer-events:none}
  label{display:block;font-size:12px;color:var(--jc-muted);margin-top:12px}input[type=text]{width:100%;padding:11px 12px;border-radius:10px;border:1px solid var(--jc-border);background:var(--jc-bg);color:var(--jc-fg);margin-top:5px;letter-spacing:.12em}input[type=range]{width:100%;accent-color:var(--jc-accent);direction:ltr;margin-top:7px}.range-value{font-variant-numeric:tabular-nums;float:inline-end}audio{width:100%;margin-top:10px}footer{margin-top:16px;padding-top:12px;border-top:1px solid var(--jc-border);display:flex;justify-content:space-between;gap:8px;font-size:10px;color:var(--jc-muted)}.spinner{display:inline-block;width:12px;height:12px;border:2px solid var(--jc-border);border-top-color:var(--jc-accent);border-radius:50%;animation:spin .8s linear infinite;margin-inline-end:6px}.math{font-size:24px;letter-spacing:.05em;text-align:center;padding:20px;background:#8395b012;border-radius:10px;direction:ltr}
  @keyframes spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.spinner{animation:none}}.hidden{display:none}
`, X = "const e=new Uint32Array([1116352408,1899447441,3049323471,3921009573,961987163,1508970993,2453635748,2870763221,3624381080,310598401,607225278,1426881987,1925078388,2162078206,2614888103,3248222580,3835390401,4022224774,264347078,604807628,770255983,1249150122,1555081692,1996064986,2554220882,2821834349,2952996808,3210313671,3336571891,3584528711,113926993,338241895,666307205,773529912,1294757372,1396182291,1695183700,1986661051,2177026350,2456956037,2730485921,2820302411,3259730800,3345764771,3516065817,3600352804,4094571909,275423344,430227734,506948616,659060556,883997877,958139571,1322822218,1537002063,1747873779,1955562222,2024104815,2227730452,2361852424,2428436474,2756734187,3204031479,3329325298]),t=(e,t)=>e>>>t|e<<32-t;function n(n){if(n.length>55||/[^\\x00-\\x7f]/.test(n))throw Error(`PoW hash accepts ASCII up to 55 bytes`);let r=new Uint32Array(64);for(let e=0;e<n.length;e++)r[e>>2]=r[e>>2]|n.charCodeAt(e)<<24-e%4*8;let i=n.length;r[i>>2]=r[i>>2]|128<<24-i%4*8,r[15]=i*8;for(let e=16;e<64;e++){let n=r[e-15],i=r[e-2];r[e]=r[e-16]+(t(n,7)^t(n,18)^n>>>3)+r[e-7]+(t(i,17)^t(i,19)^i>>>10)}let a=new Uint32Array([1779033703,3144134277,1013904242,2773480762,1359893119,2600822924,528734635,1541459225]),o=a[0],s=a[1],c=a[2],l=a[3],u=a[4],d=a[5],f=a[6],p=a[7];for(let n=0;n<64;n++){let i=p+(t(u,6)^t(u,11)^t(u,25))+(u&d^~u&f)+e[n]+r[n]|0,a=(t(o,2)^t(o,13)^t(o,22))+(o&s^o&c^s&c)|0;p=f,f=d,d=u,u=l+i|0,l=c,c=s,s=o,o=i+a|0}let m=[o,s,c,l,u,d,f,p];for(let e=0;e<8;e++)a[e]=a[e]+m[e];return a}function r(e,t,r){return!Number.isInteger(r)||r<1||r>20?!1:!(n(e+`:`+t)[0]>>>32-r)}self.onmessage=e=>{let{seed:t,bits:n}=e.data;if(!/^[A-Za-z0-9_-]{43}$/.test(t)||!Number.isInteger(n)||n<1||n>20){self.postMessage({error:`invalid_work`});return}let i=performance.now();for(let e=0;e<16777216;e++){if(r(t,String(e),n)){self.postMessage({nonce:String(e)});return}if(!(e&1023)&&performance.now()-i>5e3)break}self.postMessage({error:`work_timeout`})};", Z = typeof self < "u" && self.Blob && new Blob(["URL.revokeObjectURL(import.meta.url);", X], { type: "text/javascript;charset=utf-8" });
function Se(e) {
	let t;
	try {
		if (t = Z && (self.URL || self.webkitURL).createObjectURL(Z), !t) throw "";
		let n = new Worker(t, {
			type: "module",
			name: e?.name
		});
		return n.addEventListener("error", () => {
			(self.URL || self.webkitURL).revokeObjectURL(t);
		}), n;
	} catch {
		return new Worker("data:text/javascript;charset=utf-8," + encodeURIComponent(X), {
			type: "module",
			name: e?.name
		});
	}
}
//#endregion
//#region web/src/widget.ts
var Q = class extends Y {
	static {
		this.formAssociated = !0;
	}
	static {
		this.styles = xe;
	}
	static {
		this.properties = {
			endpoint: { type: String },
			sessionToken: { attribute: !1 },
			presentation: { type: String },
			locale: { type: String },
			theme: {
				type: String,
				reflect: !0
			},
			disabled: {
				type: Boolean,
				reflect: !0
			},
			name: {
				type: String,
				reflect: !0
			},
			status: { state: !0 },
			challenge: { state: !0 },
			errorCode: { state: !0 },
			answerText: { state: !0 },
			x: { state: !0 },
			y: { state: !0 },
			angle: { state: !0 },
			points: { state: !0 },
			cursor: { state: !0 },
			working: { state: !0 }
		};
	}
	constructor() {
		super(), this.endpoint = "/", this.sessionToken = "", this.presentation = "inline", this.locale = "en", this.theme = "auto", this.disabled = !1, this.name = "jervisCaptchaToken", this.status = "idle", this.errorCode = "", this.answerText = "", this.x = 0, this.y = 0, this.angle = 0, this.points = [], this.working = !1, this.proof = "", this.lastType = "", this.operation = 0, this.readyAt = 0, typeof this.attachInternals == "function" && (this.internals = this.attachInternals());
	}
	get token() {
		return this.status === "success" ? this.proof : "";
	}
	get t() {
		return be[this.locale === "ar" ? "ar" : "en"];
	}
	event(e, t) {
		this.dispatchEvent(new CustomEvent(e, {
			detail: t,
			bubbles: !0,
			composed: !0
		}));
	}
	invalidate() {
		this.operation++, this.controller?.abort(), this.controller = void 0, this.worker?.terminate(), this.worker = void 0, this.working = !1, this.workNonce = void 0, clearTimeout(this.expiry), this.proof = "", this.internals?.setFormValue(null), this.internals?.setValidity({ customError: !0 }, this.t.required), this.event("jervis-reset");
	}
	reset() {
		this.invalidate(), this.challenge = void 0, this.status = "idle", this.errorCode = "";
	}
	formResetCallback() {
		this.reset(), this.event("jervis-renew");
	}
	formDisabledCallback(e) {
		this.disabled = e;
	}
	formStateRestoreCallback() {
		this.reset(), this.event("jervis-renew");
	}
	disconnectedCallback() {
		this.reset(), super.disconnectedCallback();
	}
	connectedCallback() {
		super.connectedCallback(), this.hasUpdated && this.sessionToken && this.presentation !== "checkbox" && this.start();
	}
	updated(e) {
		(e.has("sessionToken") || e.has("endpoint")) && (this.reset(), this.sessionToken && this.presentation !== "checkbox" && !this.disabled && this.start()), e.has("disabled") && this.disabled && this.reset();
	}
	setExpiry(e, t) {
		clearTimeout(this.expiry), this.expiry = setTimeout(() => {
			t === this.operation && (this.invalidate(), this.status = "expired", this.event("jervis-expired"));
		}, Math.max(0, e));
	}
	async start() {
		if (!this.sessionToken || this.disabled || !this.isConnected) return;
		this.invalidate();
		let e = this.operation;
		this.controller = new AbortController(), this.challenge = void 0, this.status = "loading", this.errorCode = "", this.answerText = "", this.x = 0, this.y = 0, this.angle = 0, this.points = [], this.cursor = void 0;
		let t = performance.now();
		try {
			let n = await new r(this.endpoint).issue(this.sessionToken, this.controller.signal);
			if (e !== this.operation) return;
			if (this.challenge = n, this.lastType = n.type, this.y = n.pieceTop ?? 0, this.status = "ready", this.readyAt = performance.now() + n.minimumDelayMs, this.setExpiry(n.expiresInMs - (performance.now() - t), e), n.work) {
				if (this.working = !0, this.workNonce = await this.computeWork(n.work, this.controller.signal), e !== this.operation) return;
				this.working = !1;
			}
			n.type === "pow" && e === this.operation && await this.verify();
		} catch (t) {
			e === this.operation && this.showError(t);
		}
	}
	computeWork(t, n) {
		return new Promise((r, i) => {
			let a = new Se();
			this.worker = a;
			let o, s = (t, s) => {
				clearTimeout(o), n.removeEventListener("abort", c), a.terminate(), this.worker === a && (this.worker = void 0), s ? i(new e(s)) : r(t);
			}, c = () => s(void 0, "request_cancelled");
			if (n.addEventListener("abort", c, { once: !0 }), n.aborted) {
				c();
				return;
			}
			o = setTimeout(() => s(void 0, "work_timeout"), 6500), a.onerror = () => s(void 0, "work_unavailable"), a.onmessage = (e) => {
				e.data.error || !/^\d{1,8}$/.test(e.data.nonce ?? "") ? s(void 0, e.data.error ?? "invalid_work") : s(e.data.nonce);
			}, a.postMessage(t);
		});
	}
	showError(t) {
		this.working = !1, this.errorCode = t instanceof e ? t.code : "error", this.status = ["incorrect_answer", "too_early"].includes(this.errorCode) && this.challenge ? "ready" : "error", this.event("jervis-error", { code: this.errorCode });
	}
	answer() {
		let e = this.challenge?.type;
		return e === "slider" ? { x: this.x } : e === "drag" ? {
			x: this.x,
			y: this.y
		} : e === "rotate" ? { angle: this.angle } : e === "click" ? { points: this.points } : { text: this.answerText };
	}
	async verify() {
		let e = this.challenge;
		if (!e || this.status !== "ready" || this.disabled || this.working) return;
		let t = this.operation;
		this.status = "verifying", this.errorCode = "";
		try {
			let n = this.readyAt - performance.now();
			if (n > 0 && await new Promise((e) => setTimeout(e, n)), t !== this.operation) return;
			let i = performance.now(), a = await new r(this.endpoint).solve(this.sessionToken, e.id, this.answer(), this.workNonce, this.controller?.signal);
			if (t !== this.operation) return;
			let o = a.expiresInMs - (performance.now() - i);
			if (o <= 0) {
				this.invalidate(), this.status = "expired", this.event("jervis-expired");
				return;
			}
			this.proof = a.token, this.status = "success", this.challenge = void 0, this.internals?.setValidity({}), this.internals?.setFormValue(a.token), this.setExpiry(o, t), this.event("jervis-success", {
				...a,
				expiresInMs: o
			});
		} catch (e) {
			t === this.operation && this.showError(e);
		}
	}
	move(e) {
		let t = this.challenge;
		if (!t || this.status !== "ready" || this.disabled) return;
		let n = e.currentTarget, r = n.getBoundingClientRect(), i = (e.clientX - r.left) * (t.width ?? 300) / r.width, a = (e.clientY - r.top) * (t.height ?? 160) / r.height;
		if (t.type === "click") {
			e.type === "pointerdown" && this.points.length < 3 && (this.points = [...this.points, {
				x: i,
				y: a
			}]);
			return;
		}
		["slider", "drag"].includes(t.type) && (e.type === "pointerdown" && n.setPointerCapture(e.pointerId), (e.type === "pointerdown" || n.hasPointerCapture(e.pointerId)) && (this.x = Math.round(Math.max(0, Math.min((t.width ?? 300) - (t.pieceSize ?? 44), i - (t.pieceSize ?? 44) / 2))), t.type === "drag" && (this.y = Math.round(Math.max(0, Math.min((t.height ?? 160) - (t.pieceSize ?? 44), a - (t.pieceSize ?? 44) / 2))))));
	}
	keyboard(e) {
		if (this.challenge?.type !== "click" || this.disabled || this.status !== "ready") return;
		let t = this.cursor ?? {
			x: 150,
			y: 80
		}, n = e.shiftKey ? 1 : 10;
		if (e.key === "ArrowLeft") t.x -= n;
		else if (e.key === "ArrowRight") t.x += n;
		else if (e.key === "ArrowUp") t.y -= n;
		else if (e.key === "ArrowDown") t.y += n;
		else if (e.key === "Enter" && this.points.length < 3) this.points = [...this.points, { ...t }];
		else return;
		e.preventDefault(), this.cursor = {
			x: Math.max(0, Math.min(300, t.x)),
			y: Math.max(0, Math.min(160, t.y))
		};
	}
	range(e, t, n) {
		return R`<label>${t}<span class="range-value" aria-hidden="true">${this[e]}</span><input type="range" min="0" max=${n} step="1" .value=${String(this[e])} aria-label=${t} ?disabled=${this.disabled || this.status !== "ready"} @input=${(t) => {
			this[e] = Number(t.target.value);
		}}/></label>`;
	}
	challengeView() {
		let e = this.challenge;
		if (!e) return B;
		let t = e.image && [
			"slider",
			"drag",
			"click"
		].includes(e.type), n = this.t[e.type] ?? e.prompt;
		return R`
      <p class="prompt" id="instruction">${n}</p>
      ${t ? R`<div class="board" role="group" aria-label=${n} tabindex=${e.type === "click" ? "0" : "-1"} @keydown=${this.keyboard} @pointerdown=${this.move} @pointermove=${this.move} @pointerup=${(e) => {
			let t = e.currentTarget;
			t.hasPointerCapture(e.pointerId) && t.releasePointerCapture(e.pointerId);
		}}>
        <img src=${e.image} alt=${n} draggable="false"/>
        ${e.piece ? R`<img class="piece" src=${e.piece} alt="" draggable="false" style=${`width:${(e.pieceSize ?? 44) / (e.width ?? 300) * 100}%;left:${this.x / (e.width ?? 300) * 100}%;top:${this.y / (e.height ?? 160) * 100}%`}/>` : B}
        ${this.points.map((e, t) => R`<span class="point" style=${`left:${e.x / 3}%;top:${e.y / 1.6}%`}>${t + 1}</span>`)}
        ${this.cursor ? R`<span class="cursor" style=${`left:${this.cursor.x / 3}%;top:${this.cursor.y / 1.6}%`}></span>` : B}
      </div>` : B}
      ${e.type === "text" ? R`<img class="text-image" src=${e.image} alt=${this.t.text}/>` : B}
      ${e.type === "rotate" ? R`<div class="rotation"><img src=${e.image} alt=${this.t.rotate} style=${`transform:rotate(${this.angle}deg)`}/></div>${this.range("angle", this.t.angle, 359)}` : B}
      ${["slider", "drag"].includes(e.type) ? this.range("x", this.t.horizontal, (e.width ?? 300) - (e.pieceSize ?? 44)) : B}
      ${e.type === "drag" ? this.range("y", this.t.vertical, (e.height ?? 160) - (e.pieceSize ?? 44)) : B}
      ${e.type === "click" ? R`<button type="button" @click=${() => {
			this.points = [];
		}} ?disabled=${this.disabled || this.status !== "ready"}>${this.t.clear} (${this.points.length}/3)</button>` : B}
      ${["math", "logic"].includes(e.type) ? R`<div class="math">${e.prompt}</div>` : B}
      ${e.audio ? R`<audio controls preload="none" src=${e.audio} aria-label=${this.t.audio}></audio>` : B}
      ${[
			"text",
			"math",
			"logic",
			"audio"
		].includes(e.type) ? R`<label>${this.t.answer}<input class="answer-input" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="64" dir="ltr" aria-describedby="instruction" .value=${this.answerText} ?disabled=${this.disabled || this.status !== "ready"} @input=${(e) => {
			this.answerText = e.target.value;
		}} @keydown=${(e) => {
			e.key === "Enter" && (e.preventDefault(), this.verify());
		}}/></label>` : B}
    `;
	}
	render() {
		let e = this.presentation === "invisible" && (this.challenge?.type === "pow" || this.status === "success" && this.lastType === "pow") && this.status !== "error", t = this.errorCode ? this.t[this.errorCode] ?? this.t.error : "";
		return R`<section part="container" class=${e ? "card hidden" : "card"} dir=${this.locale === "ar" ? "rtl" : "ltr"} aria-busy=${this.status === "loading" || this.status === "verifying"}>
      <header><span class="mark" aria-hidden="true">J</span><div><h2>${this.t.title}</h2><small>${this.t.subtitle}</small></div></header>
      ${this.status === "success" ? R`<div class="success" role="status">✓ ${this.t.success}</div>` : this.challengeView()}
      ${this.status === "idle" && this.sessionToken ? R`<button class="primary" type="button" role=${this.presentation === "checkbox" ? "checkbox" : "button"} aria-checked=${this.presentation === "checkbox" ? "false" : B} @click=${() => this.start()} ?disabled=${this.disabled}>${this.t.start}</button>` : B}
      ${this.challenge && this.status === "ready" ? R`<div class="actions"><button class="primary" part="verify-button" type="button" @click=${() => this.verify()} ?disabled=${this.disabled || this.working || this.challenge.type === "pow"}>${this.t.verify}</button><button class="refresh" type="button" @click=${() => this.start()} ?disabled=${this.disabled}>${this.t.refresh}</button></div>` : B}
      <div class=${t ? "status error" : "status"} role="status" aria-live="polite">${this.working ? R`<span class="spinner"></span>${this.t.work}` : t || (this.status === "success" ? "" : this.t[this.status] ?? "")}</div>
      <footer><span>Jervis Captcha</span><span>${this.t.local}</span></footer>
    </section>`;
	}
};
//#endregion
//#region web/src/index.ts
function $() {
	typeof customElements < "u" && !customElements.get("jervis-captcha") && customElements.define("jervis-captcha", Q);
}
$();
//#endregion
export { r as CaptchaClient, e as CaptchaError, Q as JervisCaptcha, $ as registerCaptcha };
