<?php
header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $codeUnique = $input['codeUnique'] ?? '';
    $score = $input['score'] ?? null;
    $thematique = $input['thematique'] ?? '';
    $totalQuestions = $input['totalQuestions'] ?? 0;


    if (empty($codeUnique) || $score === null || empty($thematique) || $totalQuestions === 0) {
        $response['message'] = 'Données manquantes pour l\'enregistrement du score.';
        echo json_encode($response);
        exit;
    }

    $fichierDonnees = '../data/participants.json';
    $participants = [];

    if (!file_exists($fichierDonnees)) {
        $response['message'] = 'Fichier de données introuvable.';
        echo json_encode($response);
        exit;
    }

    $contenuJson = file_get_contents($fichierDonnees);
    if ($contenuJson === false) {
        $response['message'] = 'Erreur de lecture du fichier de données.';
        echo json_encode($response);
        exit;
    }

    $participants = json_decode($contenuJson, true);
    if ($participants === null && json_last_error() !== JSON_ERROR_NONE) {
        $response['message'] = 'Erreur de décodage des données JSON (fichier participants).';
        echo json_encode($response);
        exit;
    }

    if (!is_array($participants)) {
        // Si le JSON est valide mais n'est pas un tableau (ex: "null" ou une chaîne vide après trim)
        $participants = [];
    }

    $participantTrouve = false;
    foreach ($participants as $key => $participant) {
        if (isset($participant['codeUnique']) && $participant['codeUnique'] === $codeUnique) {
            // Vérifier si la thématique correspond et si le score n'a pas déjà été enregistré
            // Pour cet exemple, on écrase le score s'il existe.
            // Dans un cas réel, on pourrait vouloir empêcher la soumission multiple.
            if ($participant['thematique'] === $thematique) {
                $participants[$key]['score'] = $score;
                $participants[$key]['totalQuestionsQuiz'] = $totalQuestions; // Sauvegarder le nombre total de questions du quiz
                $participants[$key]['dateSoumissionScore'] = date('Y-m-d H:i:s');
                $participantTrouve = true;
                break;
            } else {
                $response['message'] = 'La thématique du quiz ne correspond pas à l\'inscription.';
                echo json_encode($response);
                exit;
            }
        }
    }

    if ($participantTrouve) {
        if (file_put_contents($fichierDonnees, json_encode($participants, JSON_PRETTY_PRINT))) {
            $response['success'] = true;
            $response['message'] = 'Score enregistré avec succès !';
        } else {
            $response['message'] = 'Erreur lors de l\'enregistrement du score dans le fichier.';
        }
    } else {
        $response['message'] = 'Participant non trouvé avec ce code unique.';
    }
} else {
    $response['message'] = 'Méthode de requête non autorisée.';
}

echo json_encode($response);
?>
