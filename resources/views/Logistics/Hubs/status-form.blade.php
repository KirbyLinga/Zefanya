<form method="POST" action="{{ route('logistics.hubs.status', $hub) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $hub->is_active ? 0 : 1 }}">
                                        <button type="submit" class="h-8 rounded-sm border border-[var(--border-strong)] px-3 font-sans text-[11px] font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-page)]">{{ $hub->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
