<div class="panel">
  <h3><i class="icon-link"></i> {l s='Szczegóły grupy' mod='po_linkedproduct_features'}</h3>
  <p><strong>{l s='ID' mod='po_linkedproduct_features'}:</strong> #{$group.id_group|intval}</p>
  <p><strong>{l s='Prefiks SKU' mod='po_linkedproduct_features'}:</strong> {$group.sku_prefix|escape:'html':'UTF-8'}</p>
  <p><strong>{l s='Cechy' mod='po_linkedproduct_features'}:</strong> {$group.features_label|escape:'html':'UTF-8'}</p>
  <p><strong>{l s='Aktualizacja' mod='po_linkedproduct_features'}:</strong> {$group.updated_at|escape:'html':'UTF-8'}</p>
  <form method="post" style="display:inline-block">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <input type="hidden" name="configure" value="po_linkedproduct_features">
    <input type="hidden" name="lp_section" value="groups">
    <input type="hidden" name="lp_action" value="rebuild_group">
    <input type="hidden" name="id_group" value="{$group.id_group|intval}">
    <button type="submit" class="btn btn-default" onclick="return confirm('{l s='Przebudować grupę?' mod='po_linkedproduct_features'}');">
      <i class="icon-refresh"></i> {l s='Przelicz grupę' mod='po_linkedproduct_features'}
    </button>
  </form>
  <a class="btn btn-default" href="{$current_url|escape:'html':'UTF-8'}">
    <i class="icon-arrow-left"></i> {l s='Wróć do listy' mod='po_linkedproduct_features'}
  </a>
</div>

<div class="panel">
  <h3><i class="icon-list"></i> {l s='Produkty w grupie' mod='po_linkedproduct_features'}</h3>
  <form method="get" class="form-inline">
    <input type="hidden" name="configure" value="po_linkedproduct_features">
    <input type="hidden" name="lp_section" value="groups">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <input type="hidden" name="view" value="1">
    <input type="hidden" name="id_group" value="{$group.id_group|intval}">
    <div class="form-group">
      <label class="control-label">{l s='SKU' mod='po_linkedproduct_features'}</label>
      <input type="text" name="filter_sku" value="{$filters.sku|escape:'html':'UTF-8'}" class="form-control">
    </div>
    <div class="form-group">
      <label class="control-label">{l s='ID produktu' mod='po_linkedproduct_features'}</label>
      <input type="number" name="filter_product_id" value="{$filters.product_id|escape:'html':'UTF-8'}" class="form-control" min="1">
    </div>
    <button type="submit" class="btn btn-default">
      <i class="icon-search"></i> {l s='Szukaj' mod='po_linkedproduct_features'}
    </button>
    <a class="btn btn-default" href="{$current_url|escape:'html':'UTF-8'}&view=1&id_group={$group.id_group|intval}">
      <i class="icon-refresh"></i> {l s='Wyczyść' mod='po_linkedproduct_features'}
    </a>
  </form>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>{l s='ID' mod='po_linkedproduct_features'}</th>
          <th>{l s='Nazwa' mod='po_linkedproduct_features'}</th>
          <th>{l s='SKU' mod='po_linkedproduct_features'}</th>
          <th>{l s='Aktywny' mod='po_linkedproduct_features'}</th>
          <th>{l s='Akcje' mod='po_linkedproduct_features'}</th>
        </tr>
      </thead>
      <tbody>
        {if $products|@count == 0}
          <tr>
            <td colspan="5">{l s='Brak produktów w grupie.' mod='po_linkedproduct_features'}</td>
          </tr>
        {else}
          {foreach from=$products item=product}
            <tr>
              <td>{$product.id_product|intval}</td>
              <td>{$product.name|escape:'html':'UTF-8'}</td>
              <td>{$product.reference|escape:'html':'UTF-8'}</td>
              <td>{if $product.active}{l s='Tak' mod='po_linkedproduct_features'}{else}{l s='Nie' mod='po_linkedproduct_features'}{/if}</td>
              <td>
                <form method="post" style="display:inline-block">
                  <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
                  <input type="hidden" name="configure" value="po_linkedproduct_features">
                  <input type="hidden" name="lp_section" value="groups">
                  <input type="hidden" name="lp_action" value="remove_product">
                  <input type="hidden" name="id_group" value="{$group.id_group|intval}">
                  <input type="hidden" name="id_product" value="{$product.id_product|intval}">
                  <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('{l s='Usunąć produkt z grupy?' mod='po_linkedproduct_features'}');">
                    {l s='Usuń z grupy' mod='po_linkedproduct_features'}
                  </button>
                </form>
              </td>
            </tr>
          {/foreach}
        {/if}
      </tbody>
    </table>
  </div>
  {if $total > $page_size}
    <div class="panel-footer">
      <ul class="pagination">
        {if $page > 1}
          <li><a href="{$current_url|escape:'html':'UTF-8'}&view=1&id_group={$group.id_group|intval}&page={$page-1}{$filter_query|escape:'html':'UTF-8'}">&laquo;</a></li>
        {/if}
        {section name=pages start=1 loop=$page_count+1}
          <li class="{if $smarty.section.pages.index == $page}active{/if}">
            <a href="{$current_url|escape:'html':'UTF-8'}&view=1&id_group={$group.id_group|intval}&page={$smarty.section.pages.index}{$filter_query|escape:'html':'UTF-8'}">{$smarty.section.pages.index}</a>
          </li>
        {/section}
        {if $page < $page_count}
          <li><a href="{$current_url|escape:'html':'UTF-8'}&view=1&id_group={$group.id_group|intval}&page={$page+1}{$filter_query|escape:'html':'UTF-8'}">&raquo;</a></li>
        {/if}
      </ul>
    </div>
  {/if}
</div>
