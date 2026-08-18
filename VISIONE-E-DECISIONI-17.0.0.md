
# Visione e decisioni — PR STUDIO 17.0.0

L'obiettivo non è massimizzare il numero di tool: è trasformare ragionamento in lavoro verificato con meno attrito. La Suite espone primitive concettuali semplici e mantiene routing, lane, bounded technical retry ed evidence auditabili; rollback resta solo per atomicità tecnica dopo un errore reale, mai per verification incerta.

Sono separati due benchmark:

- **PRSTUDIO-SYSTEM-BENCH**: salute infrastrutturale/contrattuale locale. Dalla formula 1.2.0 `items × questions` è dichiarato come matrice di celle-regola, non come numero di esecuzioni indipendenti; NA resta visibile e contribuisce alla copertura dell'evidenza.
- **PRSTUDIO-AGENT-BENCH**: task completion su corpus registrato. Finché il corpus non contiene i 500 task richiesti, `measured=false` e non esiste alcuno score agentico utilizzabile. Il vecchio Day Zero 2.00 è solo una calibrazione non sperimentale.

L'Agent Bench richiede 500 task: 200 public/core, 150 holdout privati, 100 procedurali e 50 adversarial rotanti. Tool/capability count vale zero. Un task fallito vale zero; contano verifica, first pass, interventi umani, tool call, tempo, recovery e continuità. L'indice può superare 100 solo battendo un riferimento congelato sugli stessi episodi.

Il loop giornaliero tenta di falsificare il candidato. `NO CHANGE` è valido. Formula, riferimento, corpus difficile e fallimenti non possono essere riscritti o esclusi per far salire il numero.
