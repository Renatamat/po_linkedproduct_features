  <form method="post">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <input type="hidden" name="configure" value="po_linkedproduct_features">
    <input type="hidden" name="lp_section" value="groups">
    <input type="hidden" name="lp_action" value="bulk_delete">
  <div class="panel">
    <h3><i class="icon-list"></i> {l s='Lista grup' mod='po_linkedproduct_features'}</h3>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th><input type="checkbox" onclick="$('.lp-group-checkbox').prop('checked', this.checked);"></th>
            <th>{l s='ID' mod='po_linkedproduct_features'}</th>
            <th>{l s='Prefiks SKU' mod='po_linkedproduct_features'}</th>
            <th>{l s='Cechy' mod='po_linkedproduct_features'}</th>
            <th>{l s='Liczba produktów' mod='po_linkedproduct_features'}</th>
            <th>{l s='Aktualizacja' mod='po_linkedproduct_features'}</th>
            <th>{l s='Akcje' mod='po_linkedproduct_features'}</th>
          </tr>
        </thead>
        <tbody>
          {if $groups|@count == 0}
            <tr>
              <td colspan="7">{l s='Brak grup do wyświetlenia.' mod='po_linkedproduct_features'}</td>
            </tr>
          {else}
            {foreach from=$groups item=group}
              <tr>
                <td><input type="checkbox" class="lp-group-checkbox" name="group_ids[]" value="{$group.id_group|intval}"></td>
                <td>#{$group.id_group|intval}</td>
                <td>{$group.sku_prefix|escape:'html':'UTF-8'}</td>
                <td>{$group.features_label|escape:'html':'UTF-8'}</td>
                <td>{$group.product_count|intval}</td>
                <td>{$group.updated_at|escape:'html':'UTF-8'}</td>
                <td>
                  <a class="btn btn-default btn-xs" href="{$current_url|escape:'html':'UTF-8'}&view=1&id_group={$group.id_group|intval}">
                    {l s='Podgląd' mod='po_linkedproduct_features'}
                  </a>
                  <button type="submit" name="lp_action" value="rebuild_group" class="btn btn-default btn-xs" formaction="{$current_url|escape:'html':'UTF-8'}&id_group={$group.id_group|intval}" onclick="return confirm('{l s='Przebudować grupę?' mod='po_linkedproduct_features'}');">
                    {l s='Przelicz' mod='po_linkedproduct_features'}
                  </button>
                  <button type="submit" name="lp_action" value="delete_group" class="btn btn-danger btn-xs" formaction="{$current_url|escape:'html':'UTF-8'}&id_group={$group.id_group|intval}" onclick="return confirm('{l s='Usunąć grupę?' mod='po_linkedproduct_features'}');">
                    {l s='Usuń' mod='po_linkedproduct_features'}
                  </button>
                </td>
              </tr>
            {/foreach}
          {/if}
        </tbody>
      </table>
    </div>
    <div class="panel-footer">
      <button type="submit" class="btn btn-danger" onclick="return confirm('{l s='Usunąć zaznaczone grupy?' mod='po_linkedproduct_features'}');">
        <i class="icon-trash"></i> {l s='Usuń zaznaczone' mod='po_linkedproduct_features'}
      </button>
    </div>
    {if $total > $page_size}
      <div class="panel-footer">
        <ul class="pagination">
          {if $page > 1}
            <li><a href="{$current_url|escape:'html':'UTF-8'}&page={$page-1}{$filter_query|escape:'html':'UTF-8'}">&laquo;</a></li>
          {/if}
          {section name=pages start=1 loop=$page_count+1}
            <li class="{if $smarty.section.pages.index == $page}active{/if}">
              <a href="{$current_url|escape:'html':'UTF-8'}&page={$smarty.section.pages.index}{$filter_query|escape:'html':'UTF-8'}">{$smarty.section.pages.index}</a>
            </li>
          {/section}
          {if $page < $page_count}
            <li><a href="{$current_url|escape:'html':'UTF-8'}&page={$page+1}{$filter_query|escape:'html':'UTF-8'}">&raquo;</a></li>
          {/if}
        </ul>
      </div>
    {/if}
  </div>
</form>

<div class="panel">
  <h3><i class="icon-plus"></i> {l s='Dodaj regułę/grupę' mod='po_linkedproduct_features'}</h3>
  <form method="post" class="defaultForm form-horizontal">
    <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
    <input type="hidden" name="configure" value="po_linkedproduct_features">
    <input type="hidden" name="lp_section" value="groups">
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Profil linkowania' mod='po_linkedproduct_features'}</label>
      <div class="col-lg-9">
        <select name="profile_id" class="form-control" required>
          <option value="">{l s='Wybierz profil' mod='po_linkedproduct_features'}</option>
          {foreach from=$profiles item=profile}
            <option value="{$profile.id_profile|intval}" {if $dry_run_input.profile_id|default:0 == $profile.id_profile}selected{/if}>{$profile.name|escape:'html':'UTF-8'}</option>
          {/foreach}
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Prefiks SKU' mod='po_linkedproduct_features'}</label>
      <div class="col-lg-9">
        <input type="text" name="sku_prefix" class="form-control" value="{$dry_run_input.prefix|default:''|escape:'html':'UTF-8'}" required>
        <p class="help-block">{l s='Dozwolone znaki: A-Z, 0-9, -, _' mod='po_linkedproduct_features'}</p>
      </div>
    </div>
    <div class="panel-footer">
      <button type="submit" name="lp_action" value="dry_run" class="btn btn-default">
        <i class="icon-eye"></i> {l s='Podgląd dopasowania' mod='po_linkedproduct_features'}
      </button>
      <button type="submit" name="lp_action" value="create_group" class="btn btn-primary pull-right" onclick="return confirm('{l s='Utworzyć grupę i wygenerować powiązania?' mod='po_linkedproduct_features'}');">
        <i class="icon-save"></i> {l s='Zapisz regułę' mod='po_linkedproduct_features'}
      </button>
    </div>
  </form>

  {if $dry_run}
    <hr>
    <h4>{l s='Podgląd dopasowania' mod='po_linkedproduct_features'}</h4>
    <p>{l s='Liczba produktów' mod='po_linkedproduct_features'}: <strong>{$dry_run.count|intval}</strong></p>
    {if $dry_run.rows|@count > 0}
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>{l s='ID' mod='po_linkedproduct_features'}</th>
              <th>{l s='Nazwa' mod='po_linkedproduct_features'}</th>
              <th>{l s='SKU' mod='po_linkedproduct_features'}</th>
              <th>{l s='Aktywny' mod='po_linkedproduct_features'}</th>
            </tr>
          </thead>
          <tbody>
            {foreach from=$dry_run.rows item=row}
              <tr>
                <td>{$row.id_product|intval}</td>
                <td>{$row.name|escape:'html':'UTF-8'}</td>
                <td>{$row.reference|escape:'html':'UTF-8'}</td>
                <td>{if $row.active}{l s='Tak' mod='po_linkedproduct_features'}{else}{l s='Nie' mod='po_linkedproduct_features'}{/if}</td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    {/if}
  {/if}
</div>
