# ADR-018 : les variables d'environnement du serveur passent avant le fichier .env

**Statut :** acceptée — remplace le point 5 de l'ADR-017 pour ce qui concerne la lecture de l'environnement

## Contexte
L'ADR-017 décidait de ne lire que le fichier `.env`, par crainte qu'une variable du système (`USER`, `PATH`) remplace un réglage sans rien dire. Or il existe deux façons courantes de configurer un site en ligne : un fichier `.env` posé sur le serveur, ou de vraies variables d'environnement réglées par le développeur chez son hébergeur, dans un conteneur ou dans la configuration du serveur web. La seconde est la norme pour les conteneurs et les plateformes, et il n'y a alors aucun `.env` en ligne. La décision de l'ADR-017 rendait ce mode de déploiement impossible.

## Options envisagées
- Rester sur le fichier seul : sûr, mais ferme la porte au déploiement le plus répandu aujourd'hui.
- Une liste explicite des variables à lire : aucune surprise, mais une configuration de plus à tenir à jour, et un oubli casse la mise en ligne.
- Lire l'environnement pour toute clé, comme Symfony et Laravel, avec un garde-fou.

## Décision
1. `Config::fromEnvFile()` cherche chaque clé dans cet ordre : la variable d'environnement de ce nom, puis le fichier `.env`, puis la valeur par défaut donnée dans le code.
2. L'environnement est **lu** (`getenv()`), jamais **écrit** : les valeurs du fichier ne sont toujours pas copiées dans `$_ENV`, `$_SERVER` ou `putenv()`.
3. Une clé ne peut pas commencer par `HTTP_`, ni dans le fichier, ni à la lecture.
4. Une valeur venue de l'environnement est vérifiée comme les autres (`int()`, `bool()`).
5. Une configuration construite à la main (`new Config([...])`) ne lit pas l'environnement, sauf demande explicite : un test reste maître de ce qu'il fournit.

## Pourquoi
- Les deux modes de déploiement fonctionnent sans rien régler de plus (principe 2), et le comportement est celui que le débutant retrouvera ailleurs.
- Sur certains serveurs (CGI, FastCGI), chaque en-tête de la requête devient une variable `HTTP_...`. Un visiteur qui envoie l'en-tête `Proxy:` crée ainsi `HTTP_PROXY` : c'est la faille « httpoxy ». Refuser ce préfixe ferme la seule voie par laquelle un visiteur pourrait choisir un réglage.
- La collision avec une variable du système reste possible mais rare, et se prévient par une convention simple : préfixer ses clés.

## Conséquences
- **Risque accepté :** une clé nommée comme une variable du système (`USER`, `HOME`, `LANG`, `PATH`, `TEMP`...) prend la valeur du système, sans avertissement. La docblock de `Config` demande de préfixer les clés (`APP_`, `DATABASE_`, `MAIL_`) ; le `.env.example` du projet de départ montrera l'exemple.
- En développement, une variable d'environnement oubliée dans le terminal l'emporte sur le `.env` : le message d'une clé manquante cite les deux sources pour aider à s'y retrouver.
- `var_dump()` de la configuration ne montre que les clés du fichier : celles qui ne viennent que de l'environnement n'y figurent pas.
- Une bibliothèque tierce qui lit `getenv()` voit les variables du serveur, mais toujours pas les valeurs du fichier.
