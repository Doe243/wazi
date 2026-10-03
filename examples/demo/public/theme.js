// Applique le thème choisi par le visiteur (clair ou sombre), s'il en a choisi un.
//
// Ce script est chargé dans l'en-tête de la page, AVANT son affichage : sans
// cela, une page sombre apparaîtrait d'abord claire, le temps d'un clignement.
// Sans choix enregistré, la feuille de style suit le réglage de l'appareil.
//
// Le choix est gardé par le navigateur (localStorage), pas par le serveur :
// il ne concerne que l'affichage, et aucun cookie n'est nécessaire.
try {
    const theme = localStorage.getItem('theme');

    if (theme === 'light' || theme === 'dark') {
        document.documentElement.dataset.theme = theme;
    }
} catch {
    // Le navigateur interdit le stockage (navigation privée stricte) : on garde le thème de l'appareil.
}
