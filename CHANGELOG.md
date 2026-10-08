# Changelog

Toutes les évolutions notables de ce plugin. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [2.0.0] - 2026-10-08

Réécriture complète de la version 1.1 (« lienbt »).

### Ajouté
- Copies WebP / AVIF **à côté** des originaux, pour l'image principale et toutes les miniatures. Une copie
  plus lourde que l'original n'est pas gardée.
- Affichage par balise `<picture>` (contenus **et** images du thème), ou remplacement d'URL par le WebP.
- Optimisation automatique à l'envoi, optimisation en masse avec barre de progression, restauration.
- Onglet *Textes alternatifs* : filtre « sans alt », édition en ligne, suggestion par IA ou depuis le nom de
  fichier, remplissage en masse.
- Client IA compatible Ollama **et** API OpenAI (clé d'API, test de connexion, langue du site, contexte
  de la page).
- Alt vides des contenus complétés à l'affichage depuis la médiathèque.
- Réglage du nombre d'images non différées en haut de page.
- Commandes WP-CLI `wp isp optimize | restore | stats | alt`.
- Colonne « Optimisation » dans la médiathèque.
- Traduction française, `uninstall.php`, tests d'intégration et CI GitHub (GD et Imagick).

### Corrigé (par rapport à la 1.1)
- La conversion **supprimait les originaux** et changeait le type du média. Les fichiers sont désormais
  intacts.
- « Compresser » écrivait du WebP dans des fichiers `.jpg`.
- Les boutons de conversion et de compression ne faisaient plus rien (code JavaScript perdu).
- Les PNG transparents recevaient un fond blanc.
- Les actions AJAX ne vérifiaient pas les droits de l'utilisateur.
- Les images sans méta d'alt n'étaient pas comptées comme « sans alt ».
- La limite mémoire était relevée à 512 Mo sur chaque page du site.

### Retiré
- Cache de pages, minification HTML et report du JavaScript : ces fonctions n'étaient ni réglables ni
  fiables, et elles relèvent d'un plugin de cache dédié.
