# plugin-ValuersManagement

🧩 Funcionalidades principais implementadas
Importação via planilha Excel (.xlsx):
A planilha pode conter colunas como: inscrição e id do avaliador.

Distribuição automática das avaliações:
Para cada linha válida, é criada uma entrada em registration_evaluation, associando a inscrição ao avaliador correspondente.

Leitura correta da comissão (committee):
O nome da comissão é buscado com base no relacionamento entre EvaluationMethodConfiguration e os avaliadores (via agent_relation).

Isso reproduz exatamente a lógica da migração de banco usada pelo Mapas Culturais.
Evita duplicatas:
Antes de criar uma nova avaliação, verifica se já existe uma para o mesmo registration_id, user_id e committee.

Log detalhado das operações:
Todos os passos, erros e decisões do processo são registrados no arquivo logs/valuersmanagement.log.

🛠️ Estrutura técnica
Plugin baseado em MapasCulturais\Plugin.
Integração com a tela de fases de avaliação via hook para upload de planilha.
Processamento via rota GET(opportunity.valuersmanagement).
Registro de grupo de arquivos evalmaster vinculado à entidade opportunity.

🧪 Testado e funcional
Testes realizados com planilha real.
Entradas corretas salvas na base de dados.
Comissão salva corretamente no campo committee.
Sem erros de JSON ou conflitos com o frontend.