<div class="row">
  <div class="col-xs-12">
    <div class="box box-primary">
      <div class="box-header with-border">
        <h3 class="box-title">PRM Auto Deploy</h3>
      </div>
      <div class="box-body">
        <p>
          <a href="{{ route('admin.servers') }}">Admin → Servers</a> sayfasında
          <strong>Create New</strong> yanındaki <strong>Auto Deploy</strong> butonunu kullan.
        </p>
        <ul style="margin-bottom:0">
          <li>Egg seç → aynı egg'den kurulu bir sunucu donor olur.</li>
          <li>Oyun dosyaları (~68GB) hardlink ile paylaşılır (Steam tekrar inmez).</li>
          <li><code>cfg</code> ve <code>addons</code> sunucu başına ayrı; stok map dosyaları paylaşılır, özel map için <code>maps/_server</code>.</li>
          <li>Portlar sırayla atanır; istersen 10 sunucuyu tek seferde açarsın.</li>
        </ul>
      </div>
    </div>
  </div>
</div>
