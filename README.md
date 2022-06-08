# Fabrik API

## Atualizações
### Atualização de URL

#### Motivação 
<p style="text-align: justify">Pelo fato do link da url a ser buscada via GET tornava-se muito grande para GETs simples, foi necessário modificar a forma como era recebido os dados via GET.</p>

#### Modificações 
<p style="text-align: justify">Primeiramente, todas as moficações passam pela critério se existe na própria url o parâmetro de chave da API, pois se não possuir o código deve funcionar como o original, desta forma, a linha de código presente antes da alteração principal é:</p>

```
if(isset($_GET['api_key'])) {
```

<p style="text-align: justify">Em seguida, são resgatados os dados da URL  por um novo formato estabelecido a seguir:</p>

<p style="text-align: center">URL = Base + Formatação + Autenticação + Opções de Busca</p>

##### Exemplo:

* https://selecao2.cett.dev.br/index.php?
* option=com_fabrik&format=raw&task=plugin.pluginAjax&plugin=fabrik_api&method=apiCalled&g=list&
* api_key=string&api_secret=string&
* options=list_id:int|data_type:string|type:string|row_id:int|filters:element0#value0..element1#value1..element2#value2..element3#value3

<p style="text-align: justify">Dessa forma, todos os dados de busca são passados por meio do parâmetro options sendo cada um separado por "|" e dentro do parametro filters cada um filtro separado por ".." com o nome do elemento no formato do fabrik seguido de "#" e o valor desejado.</p>
