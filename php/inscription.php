<?php
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'codeUnique' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = $_POST['nom'] ?? '';
    $telephone = $_POST['telephone'] ?? '';
    $adresse = $_POST['adresse'] ?? '';
    $thematique = $_POST['thematique'] ?? '';

    if (empty($nom) || empty($telephone) || empty($adresse) || empty($thematique)) {
        $response['message'] = 'Tous les champs sont requis.';
    } else {
        // Génération d'un code unique simple (pour l'exemple)
        // Dans une application réelle, utiliser quelque chose de plus robuste et vérifier les collisions.
        $codeUnique = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));

        $participant = [
            'codeUnique' => $codeUnique,
            'nom' => $nom,
            'telephone' => $telephone,
            'adresse' => $adresse,
            'thematique' => $thematique,
            'score' => null, // Score initial
            'dateInscription' => date('Y-m-d H:i:s')
        ];

        $fichierDonnees = '../data/participants.json';
        $participants = [];

        if (file_exists($fichierDonnees)) {
            $contenuJson = file_get_contents($fichierDonnees);
            if ($contenuJson === false) {
                $response['message'] = 'Erreur de lecture du fichier de données.';
                echo json_encode($response);
                exit;
            }
            $participants = json_decode($contenuJson, true);
            if ($participants === null && json_last_error() !== JSON_ERROR_NONE) {
                // Gérer l'erreur de décodage JSON, par exemple en initialisant avec un tableau vide
                // ou en signalant une corruption de données.
                $response['message'] = 'Erreur de décodage des données JSON. Le fichier est peut-être corrompu.';
                 // Pour cet exemple, nous allons essayer de réinitialiser si le fichier est vide ou mal formé
                if (empty(trim($contenuJson))) {
                    $participants = [];
                } else {
                    echo json_encode($response);
                    exit;
                }
            }
        }

        // Assurer que $participants est un tableau
        if (!is_array($participants)) {
            $participants = [];
        }

        $participants[] = $participant;

        if (file_put_contents($fichierDonnees, json_encode($participants, JSON_PRETTY_PRINT))) {
            $response['success'] = true;
            $response['message'] = 'Inscription réussie !';
            $response['codeUnique'] = $codeUnique;
        } else {
            $response['message'] = 'Erreur lors de l\'enregistrement des données.';
        }
    }
} else {
    $response['message'] = 'Méthode de requête non autorisée.';
}

echo json_encode($response);
?>
