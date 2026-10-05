/*! wazi.js : les zones mises à jour de Wazi. Installé par « wazi zones:install » ; ne le modifiez pas, il est remplacé à chaque installation. */

/*
 * Ce que fait ce fichier
 * ----------------------
 *
 * Dans un template, vous marquez un morceau de page :
 *
 *     <ul k:zone="liste"> ... </ul>
 *
 * et vous dites quel formulaire ou quel lien le met à jour :
 *
 *     <form method="post" action="/notes" k:update="liste"> ... </form>
 *
 * Sans ce fichier, le formulaire recharge toute la page, comme toujours.
 * Avec lui :
 *
 *     1. il fait la MÊME requête que le navigateur aurait faite ;
 *     2. le serveur répond la MÊME page, entière ;
 *     3. il ne remplace, dans la page affichée, que les zones nommées.
 *
 * Votre contrôleur ne change donc pas, et la page marche sans JavaScript.
 *
 * Quand il ne peut pas faire proprement (autre site, zone absente de la page
 * reçue, réseau coupé), il laisse le navigateur faire, ou affiche la page
 * reçue en entier.
 *
 * Sécurité : ce fichier n'insère que des pages venues de votre site, et le
 * navigateur n'exécute pas les scripts d'un morceau de page inséré ainsi.
 */
(() => {
    'use strict';

    /** La requête en cours : une nouvelle demande l'abandonne. */
    let pending = null;

    // ------------------------------------------------------------------
    // Écouter : un formulaire envoyé, un lien cliqué
    // ------------------------------------------------------------------

    // On écoute sur « window », le dernier servi : un script de votre page qui
    // annule l'envoi (pour demander une confirmation, par exemple) passe avant,
    // et ce fichier respecte sa décision.
    window.addEventListener('submit', (event) => {
        const form = event.target;

        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !form.hasAttribute('data-k-update')) {
            return;
        }

        // Le bouton cliqué peut changer l'adresse ou la méthode (formaction, formmethod).
        const button = event.submitter;
        const url = new URL(button?.getAttribute('formaction') ?? form.getAttribute('action') ?? '', document.baseURI);
        const method = (button?.getAttribute('formmethod') ?? form.getAttribute('method') ?? 'get').toLowerCase();
        const target = button?.getAttribute('formtarget') ?? form.getAttribute('target');

        const names = zonesOf(form);

        if (names.length === 0 || !isOurs(url) || (target !== null && target !== '_self') || (method !== 'get' && method !== 'post')) {
            return;
        }

        event.preventDefault();

        const fields = new FormData(form, button);
        const options = { method: method.toUpperCase() };

        if (method === 'get') {
            // Comme le navigateur : les champs remplacent ce qui suivait le « ? ».
            url.search = new URLSearchParams(textFields(fields)).toString();
        } else if (form.enctype === 'multipart/form-data') {
            options.body = fields;
        } else {
            options.body = new URLSearchParams(textFields(fields));
        }

        update(url, options, names, () => nativeSubmit(form, button));
    });

    window.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[data-k-update]') : null;

        // Un clic avec une touche, ou du bouton du milieu, ouvre un nouvel
        // onglet : c'est au navigateur de le faire.
        if (link === null || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || link.hasAttribute('download') || (link.target !== '' && link.target !== '_self')
        ) {
            return;
        }

        const url = new URL(link.href, document.baseURI);

        const names = zonesOf(link);

        if (names.length === 0 || !isOurs(url)) {
            return;
        }

        event.preventDefault();
        update(url, { method: 'GET' }, names, () => window.location.assign(url.href));
    });

    // Le bouton « Précédent » revient à une adresse que ce fichier a affichée :
    // on redemande la page, pour montrer ce qui correspond à l'adresse.
    window.addEventListener('popstate', () => window.location.reload());

    // ------------------------------------------------------------------
    // Demander la page, remplacer les zones
    // ------------------------------------------------------------------

    /**
     * @param {URL}         url      l'adresse demandée
     * @param {RequestInit} options  la méthode, et les champs d'un formulaire
     * @param {string[]}    names    les zones à remplacer
     * @param {() => void}  fallback ce que le navigateur aurait fait, si tout échoue
     */
    async function update(url, options, names, fallback) {
        pending?.abort();
        pending = new AbortController();

        const signal = pending.signal;
        const zones = names.map((name) => find(name)).filter((zone) => zone !== null);

        zones.forEach((zone) => zone.setAttribute('aria-busy', 'true'));

        let response;
        let html;

        try {
            response = await fetch(url, {
                ...options,
                signal,
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
            });
            html = await response.text();
        } catch {
            zones.forEach((zone) => zone.removeAttribute('aria-busy'));

            // Une demande plus récente a pris la place : rien à faire. Sinon,
            // le réseau a échoué : le navigateur fait l'envoi lui-même, et
            // montre sa propre page d'erreur s'il échoue aussi.
            if (!signal.aborted) {
                fallback();
            }

            return;
        }

        const type = response.headers.get('Content-Type') ?? '';

        // Autre chose qu'une page (un fichier à télécharger, du JSON) : ce
        // n'est pas à ce fichier de s'en occuper. Le navigateur y va lui-même,
        // sauf après un envoi sans redirection, qu'il ne faut pas refaire.
        if (!type.startsWith('text/html')) {
            zones.forEach((zone) => zone.removeAttribute('aria-busy'));

            if (options.method === 'GET' || response.redirected) {
                window.location.assign(response.url);
            }

            return;
        }

        const received = new DOMParser().parseFromString(html, 'text/html');
        const fresh = names.map((name) => find(name, received));

        // Une zone manque, ici ou dans la page reçue : c'est une autre page
        // (connexion demandée, erreur). On la montre en entier.
        if (zones.length !== names.length || fresh.includes(null)) {
            showWholePage(received, response, options.method);

            return;
        }

        const focused = document.activeElement?.id ?? '';

        zones.forEach((zone, index) => zone.replaceWith(document.adoptNode(fresh[index])));

        // La barre de débogage de Wazi, si elle est là, décrit la dernière requête.
        const bar = received.getElementById('wz-barre');

        if (bar !== null) {
            document.getElementById('wz-barre')?.replaceWith(document.adoptNode(bar));
        }

        if (received.title !== '') {
            document.title = received.title;
        }

        follow(response, options.method);

        // Si le champ où l'on écrivait vient d'être remplacé, on y revient.
        if (focused !== '' && document.activeElement === document.body) {
            document.getElementById(focused)?.focus();
        }

        document.dispatchEvent(new CustomEvent('wazi:updated', { detail: { zones: names, url: response.url } }));
    }

    /**
     * Met l'adresse du navigateur à jour quand elle désigne bien ce qui est
     * affiché : après un lien, un formulaire GET, ou un envoi suivi d'une
     * redirection. Après un envoi sans redirection, l'adresse ne change pas.
     */
    function follow(response, method) {
        if ((method === 'GET' || response.redirected) && response.url !== window.location.href) {
            window.history.pushState({ wazi: true }, '', response.url);
        }
    }

    /**
     * Affiche la page reçue à la place de la page actuelle.
     */
    function showWholePage(received, response, method) {
        // Une page qu'on peut redemander sans rien refaire : le navigateur y
        // va lui-même, ses scripts et ses styles seront chargés normalement.
        if (method === 'GET' || response.redirected) {
            window.location.assign(response.url);

            return;
        }

        // La réponse directe à un envoi (formulaire refusé, erreur) : la
        // redemander renverrait le formulaire une seconde fois. On affiche
        // donc ce qui a été reçu.
        document.replaceChild(document.adoptNode(received.documentElement), document.documentElement);
        window.scrollTo(0, 0);
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    /** Vrai si l'adresse est une page de ce site. */
    function isOurs(url) {
        return url.origin === window.location.origin && (url.protocol === 'http:' || url.protocol === 'https:');
    }

    /** Les zones nommées par data-k-update="liste compteur". */
    function zonesOf(element) {
        return (element.getAttribute('data-k-update') ?? '').split(' ').filter((name) => /^[a-z][a-z0-9-]*$/.test(name));
    }

    /** La zone de ce nom, dans la page affichée ou dans une page reçue. */
    function find(name, page = document) {
        return page.querySelector('[data-k-zone="' + name + '"]');
    }

    /** Les champs d'un formulaire, sans ses fichiers (qui ne s'envoient qu'en multipart/form-data). */
    function textFields(fields) {
        return [...fields].filter(([, value]) => typeof value === 'string');
    }

    /** L'envoi ordinaire du formulaire, sans repasser par ce fichier. */
    function nativeSubmit(form, button) {
        form.removeAttribute('data-k-update');
        form.requestSubmit(button ?? undefined);
    }
})();
