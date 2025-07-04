document.addEventListener('DOMContentLoaded', function () {
    const formConnexionQuiz = document.getElementById('form-connexion-quiz');
    const inputCodeQuiz = document.getElementById('code-quiz');
    const messageErreurCode = document.getElementById('message-erreur-code');
    const sectionConnexionQuiz = document.getElementById('connexion-quiz');
    const sectionEspaceQuiz = document.getElementById('espace-quiz');
    const nomThematiqueQuiz = document.getElementById('nom-thematique-quiz');
    const thematiqueQuizValeur = document.getElementById('thematique-quiz-valeur');
    const chronometreDisplay = document.getElementById('chronometre');
    const btnCommencerQuiz = document.getElementById('btn-commencer-quiz');
    const divQuestionsQuiz = document.getElementById('questions-quiz');
    const btnTerminerQuiz = document.getElementById('btn-terminer-quiz');

    let timerInterval;
    let tempsRestant = 240; // 4 minutes en secondes
    let questionsActuelles = [];
    let score = 0;
    let participantData = null; // Pour stocker les données du participant

    // Charger les questions (factices pour l'instant)
    const questionsDb = {
        "Football": [
            { q: "Qui a remporté la Coupe du Monde de la FIFA 2022 ?", options: ["France", "Argentine", "Brésil", "Allemagne"], reponse: "Argentine" },
            { q: "Combien de joueurs une équipe de football compte-t-elle sur le terrain ?", options: ["10", "11", "12", "9"], reponse: "11" },
            // Ajouter 18 autres questions
        ],
        "Culture generale": [
            { q: "Quelle est la capitale de la France ?", options: ["Berlin", "Madrid", "Paris", "Rome"], reponse: "Paris" },
            { q: "Qui a peint la Joconde ?", options: ["Vincent Van Gogh", "Pablo Picasso", "Léonard de Vinci", "Claude Monet"], reponse: "Léonard de Vinci" },
            // Ajouter 18 autres questions
        ],
        "Musique": [
            { q: "Quel instrument de musique possède des cordes et des touches noires et blanches ?", options: ["Guitare", "Violon", "Piano", "Batterie"], reponse: "Piano" },
            { q: "Qui est surnommé le 'King of Pop' ?", options: ["Elvis Presley", "Michael Jackson", "Stevie Wonder", "James Brown"], reponse: "Michael Jackson" },
            // Ajouter 18 autres questions
        ]
    };
    // Pour la démo, on va juste dupliquer les questions pour arriver à 20 si nécessaire
    for (const thematique in questionsDb) {
        while (questionsDb[thematique].length < 20 && questionsDb[thematique].length > 0) {
            questionsDb[thematique].push(...questionsDb[thematique].slice(0, 20 - questionsDb[thematique].length));
        }
    }


    if (formConnexionQuiz) {
        // Si un code est passé en URL (depuis index.html)
        const urlParams = new URLSearchParams(window.location.search);
        const codeUrl = urlParams.get('code');
        if (codeUrl && inputCodeQuiz) {
            inputCodeQuiz.value = codeUrl;
            verifierCode(codeUrl);
        }

        formConnexionQuiz.addEventListener('submit', function (event) {
            event.preventDefault();
            if (inputCodeQuiz) {
                const code = inputCodeQuiz.value.trim().toUpperCase();
                if (code) {
                    verifierCode(code);
                } else {
                    afficherMessageErreur("Veuillez entrer un code.");
                }
            }
        });
    }

    function verifierCode(code) {
        // Simuler la vérification du code en allant chercher dans le fichier JSON
        // Dans une vraie application, ce serait un appel AJAX vers un script PHP
        fetch('../data/participants.json')
            .then(response => {
                if (!response.ok) {
                    throw new Error("Fichier participants.json non trouvé ou erreur serveur.");
                }
                return response.json();
            })
            .then(participants => {
                participantData = participants.find(p => p.codeUnique === code);
                if (participantData) {
                    if (sectionConnexionQuiz) sectionConnexionQuiz.style.display = 'none';
                    if (sectionEspaceQuiz) sectionEspaceQuiz.style.display = 'block';
                    if (nomThematiqueQuiz) nomThematiqueQuiz.textContent = `- ${participantData.thematique}`;
                    if (thematiqueQuizValeur) thematiqueQuizValeur.textContent = participantData.thematique;
                    questionsActuelles = questionsDb[participantData.thematique] || [];
                    if (questionsActuelles.length === 0) {
                        afficherMessageErreur(`Aucune question disponible pour la thématique : ${participantData.thematique}.`);
                        if (sectionEspaceQuiz) sectionEspaceQuiz.style.display = 'none';
                        if (sectionConnexionQuiz) sectionConnexionQuiz.style.display = 'block'; // Réafficher la section de connexion
                        return;
                    }
                    if (btnCommencerQuiz) btnCommencerQuiz.style.display = 'block';
                    if (messageErreurCode) messageErreurCode.textContent = '';
                } else {
                    afficherMessageErreur("Code invalide ou non trouvé.");
                    participantData = null;
                }
            })
            .catch(error => {
                console.error("Erreur lors de la vérification du code:", error);
                // Tentative de détection d'un fichier non trouvé (peut varier selon le serveur/navigateur)
                // Une erreur de type "Failed to fetch" ou une réponse avec statut 404 interceptée plus tôt serait plus fiable.
                // Ici, on se base sur le message d'erreur qui est moins robuste.
                if (error instanceof TypeError && (error.message.toLowerCase().includes('failed to fetch') || error.message.toLowerCase().includes('networkerror'))) {
                     afficherMessageErreur("Impossible de charger les données des participants. Le fichier est peut-être manquant ou inaccessible sur le serveur.");
                } else if (error.message.includes("participants.json non trouvé")) { // Si l'on a lancé l'erreur spécifique
                     afficherMessageErreur("Fichier des participants non trouvé. Veuillez d'abord vous inscrire.");
                }
                else {
                     afficherMessageErreur("Erreur de communication ou de traitement des données pour vérifier le code.");
                }
                participantData = null;
            });
    }

    function afficherMessageErreur(message) {
        if (messageErreurCode) {
            messageErreurCode.textContent = message;
        }
    }

    if (btnCommencerQuiz) {
        btnCommencerQuiz.addEventListener('click', function () {
            btnCommencerQuiz.style.display = 'none';
            if (divQuestionsQuiz) divQuestionsQuiz.style.display = 'block';
            if (btnTerminerQuiz) btnTerminerQuiz.style.display = 'block';
            lancerQuiz();
        });
    }

    function lancerQuiz() {
        tempsRestant = 240; // Réinitialiser le temps
        score = 0;
        afficherQuestions();
        demarrerChronometre();
    }

    function demarrerChronometre() {
        if (timerInterval) clearInterval(timerInterval); // S'assurer qu'un seul timer tourne

        timerInterval = setInterval(() => {
            tempsRestant--;
            const minutes = Math.floor(tempsRestant / 60);
            const secondes = tempsRestant % 60;
            if (chronometreDisplay) {
                chronometreDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${secondes.toString().padStart(2, '0')}`;
            }

            if (tempsRestant <= 0) {
                clearInterval(timerInterval);
                terminerQuizAutomatiquement();
            }
        }, 1000);
    }

    function afficherQuestions() {
        if (!divQuestionsQuiz) return;
        divQuestionsQuiz.innerHTML = ''; // Vider les questions précédentes

        questionsActuelles.forEach((q, index) => {
            const questionElement = document.createElement('article');
            questionElement.classList.add('question');
            questionElement.innerHTML = `
                <h3>Question ${index + 1}</h3>
                <p>${q.q}</p>
                <div class="options">
                    ${q.options.map((option, i) => `
                        <div>
                            <input type="radio" name="question-${index}" id="q-${index}-option-${i}" value="${option}">
                            <label for="q-${index}-option-${i}">${option}</label>
                        </div>
                    `).join('')}
                </div>
            `;
            divQuestionsQuiz.appendChild(questionElement);
        });
    }

    function calculerScore() {
        score = 0;
        questionsActuelles.forEach((q, index) => {
            const selectedOption = document.querySelector(`input[name="question-${index}"]:checked`);
            if (selectedOption && selectedOption.value === q.reponse) {
                score++;
            }
        });
    }

    function terminerQuizAutomatiquement() {
        // Gérer la fin du quiz quand le temps est écoulé
        calculerScore();
        // Rediriger vers la page des résultats avec le score et la thématique
        // et le code unique pour enregistrer le score
        window.location.href = `resultats.html?score=${score}&thematique=${encodeURIComponent(participantData.thematique)}&total=${questionsActuelles.length}&code=${participantData.codeUnique}`;
    }

    if (btnTerminerQuiz) {
        btnTerminerQuiz.addEventListener('click', function () {
            clearInterval(timerInterval); // Arrêter le chrono
            terminerQuizAutomatiquement(); // Calcule le score et redirige
        });
    }
});
