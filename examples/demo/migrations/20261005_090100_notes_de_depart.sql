-- Les notes que contient le carnet au premier lancement.
--
-- Une migration change d'ordinaire la STRUCTURE de la base. Celle-ci y range
-- aussi quelques lignes, pour que la démonstration ait quelque chose à montrer.
--
-- datetime('now', '-6 days') : « il y a six jours », calculé par SQLite au
-- moment où la migration est appliquée, à l'heure universelle.
--
-- Une apostrophe dans un texte SQL s'écrit en la doublant : 'd''écrire'.

INSERT INTO notes (id, auteur, texte, couleur, importante, creee_le) VALUES
    (1, 'alice', 'Lire le code du routeur : il tient en un fichier, et se lit en dix minutes.', 'lagon', 1, datetime('now', '-6 days')),
    (2, 'alice', 'Essayer d''écrire <script>alert(1)</script> dans une note. Kioo l''affiche, le navigateur ne l''exécute pas.', 'corail', 0, datetime('now', '-3 days')),
    (3, 'alice', 'Idée : un filtre Kioo « depuis », pour écrire « il y a 3 h » à la place d''une date.', 'miel', 1, datetime('now', '-26 hours')),
    (4, 'bob', 'Cette note est à Bob : Alice ne peut pas la voir.', 'ciel', 0, datetime('now', '-1 day')),
    (5, 'alice', 'Acheter du pain.', 'neutre', 0, datetime('now', '-5 hours')),
    (6, 'alice', 'Ouvrir /notes/4 : la note de Bob doit rester introuvable.', 'ciel', 0, datetime('now', '-50 minutes')),
    (7, 'alice', 'Passer le site en thème sombre, pour voir.', 'neutre', 0, datetime('now', '-4 minutes')),
    (8, 'bob', 'Relire la page « La sécurité » du guide.', 'lagon', 1, datetime('now', '-2 hours'));
