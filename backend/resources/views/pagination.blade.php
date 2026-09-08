@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginación" class="flex flex-col sm:flex-row items-center justify-between gap-3 py-3">
        <p class="text-sm">Mostrando {{ $paginator->firstItem() }} a {{ $paginator->lastItem() }} de {{ $paginator->total() }} resultados</p>
        <div class="flex items-center gap-2">
            <button type="button" class="btn btn-sm" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled($paginator->onFirstPage())>Anterior</button>
            <span class="text-sm" aria-current="page">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
            <button type="button" class="btn btn-sm" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages())>Siguiente</button>
        </div>
    </nav>
@endif
