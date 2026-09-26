import {
  ClipboardEvent,
  FormEvent,
  KeyboardEvent,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';

export type RichEditorMediaItem = {
  id: number;
  title: string;
  url: string;
  alt: string;
};

type AdminRichEditorProps = {
  value: string;
  onChange: (html: string) => void;
  disabled?: boolean;
  onSave?: () => void;
  canSave?: boolean;
  loadMedia?: (query: string) => Promise<RichEditorMediaItem[]>;
  minHeight?: number;
  label?: string;
};

type CommandButtonProps = {
  label: string;
  title: string;
  command?: string;
  value?: string;
  disabled?: boolean;
  onClick?: () => void;
  className?: string;
};

function escapeHtml(value: string) {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function sanitizePastedHtml(html: string) {
  const parser = new DOMParser();
  const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
  const root = doc.body.firstElementChild;

  if (!root) return '';

  root.querySelectorAll(
    'script,style,iframe,object,embed,form,input,button,select,textarea,meta,link,noscript',
  ).forEach((node) => node.remove());

  const walker = doc.createTreeWalker(root, NodeFilter.SHOW_COMMENT);
  const comments: Comment[] = [];
  while (walker.nextNode()) comments.push(walker.currentNode as Comment);
  comments.forEach((comment) => comment.remove());

  root.querySelectorAll<HTMLElement>('*').forEach((element) => {
    const style = element.style;
    const weight = style.fontWeight;
    const italic = style.fontStyle === 'italic';
    const underline = style.textDecorationLine.includes('underline')
      || style.textDecoration.includes('underline');
    const strike = style.textDecorationLine.includes('line-through')
      || style.textDecoration.includes('line-through');
    const textAlign = style.textAlign;

    if (
      element.tagName === 'SPAN'
      && (weight === 'bold' || Number.parseInt(weight, 10) >= 600)
    ) {
      const strong = doc.createElement('strong');
      while (element.firstChild) strong.appendChild(element.firstChild);
      element.replaceWith(strong);
      element = strong;
    }

    if (element.tagName === 'SPAN' && italic) {
      const em = doc.createElement('em');
      while (element.firstChild) em.appendChild(element.firstChild);
      element.replaceWith(em);
      element = em;
    }

    if (element.tagName === 'SPAN' && underline) {
      const underlineNode = doc.createElement('u');
      while (element.firstChild) underlineNode.appendChild(element.firstChild);
      element.replaceWith(underlineNode);
      element = underlineNode;
    }

    if (element.tagName === 'SPAN' && strike) {
      const strikeNode = doc.createElement('s');
      while (element.firstChild) strikeNode.appendChild(element.firstChild);
      element.replaceWith(strikeNode);
      element = strikeNode;
    }

    if (
      ['left', 'center', 'right', 'justify'].includes(textAlign)
      && ['P', 'DIV', 'H2', 'H3', 'H4', 'BLOCKQUOTE'].includes(element.tagName)
    ) {
      element.setAttribute('align', textAlign);
    }

    const allowed = new Set([
      'href',
      'src',
      'alt',
      'title',
      'target',
      'rel',
      'colspan',
      'rowspan',
      'align',
      'width',
      'height',
    ]);

    [...element.attributes].forEach((attribute) => {
      if (!allowed.has(attribute.name.toLowerCase())) {
        element.removeAttribute(attribute.name);
      }
    });

    if (element.tagName === 'A') {
      const href = element.getAttribute('href') ?? '';
      if (/^javascript:/i.test(href)) {
        element.removeAttribute('href');
      } else if (href) {
        element.setAttribute('rel', 'noopener noreferrer');
      }
    }

    if (element.tagName === 'IMG') {
      const src = element.getAttribute('src') ?? '';
      if (/^(javascript|data:text/html):/i.test(src)) {
        element.remove();
      }
    }
  });

  return root.innerHTML;
}

function countWords(html: string) {
  const element = document.createElement('div');
  element.innerHTML = html;
  const text = (element.textContent ?? '').trim();
  if (!text) return 0;
  return text.split(/\s+/u).filter(Boolean).length;
}

function countCharacters(html: string) {
  const element = document.createElement('div');
  element.innerHTML = html;
  return (element.textContent ?? '').length;
}

function AdminCommandButton({
  label,
  title,
  command,
  value,
  disabled,
  onClick,
  className = '',
}: CommandButtonProps) {
  function execute() {
    if (disabled) return;
    if (onClick) {
      onClick();
      return;
    }

    if (command) {
      document.execCommand(command, false, value);
    }
  }

  return (
    <button
      type="button"
      className={'admin-rich-editor__button ' + className}
      title={title}
      aria-label={title}
      disabled={disabled}
      onMouseDown={(event) => {
        event.preventDefault();
        execute();
      }}
    >
      {label}
    </button>
  );
}

export function AdminRichEditor({
  value,
  onChange,
  disabled = false,
  onSave,
  canSave = false,
  loadMedia,
  minHeight = 520,
  label = 'Conteúdo',
}: AdminRichEditorProps) {
  const editorRef = useRef<HTMLDivElement>(null);
  const selectionRef = useRef<Range | null>(null);
  const [sourceMode, setSourceMode] = useState(false);
  const [fullscreen, setFullscreen] = useState(false);
  const [source, setSource] = useState(value);
  const [mediaOpen, setMediaOpen] = useState(false);
  const [mediaQuery, setMediaQuery] = useState('');
  const [mediaItems, setMediaItems] = useState<RichEditorMediaItem[]>([]);
  const [mediaLoading, setMediaLoading] = useState(false);
  const [mediaError, setMediaError] = useState(false);

  const wordCount = useMemo(() => countWords(value), [value]);
  const characterCount = useMemo(() => countCharacters(value), [value]);
  const readingMinutes = useMemo(
    () => (wordCount === 0 ? 0 : Math.max(1, Math.ceil(wordCount / 220))),
    [wordCount],
  );
  const structureStats = useMemo(() => {
    const element = document.createElement('div');
    element.innerHTML = value;
    return {
      headings: element.querySelectorAll('h2, h3').length,
      images: element.querySelectorAll('img').length,
      links: element.querySelectorAll('a[href]').length,
    };
  }, [value]);

  useEffect(() => {
    if (sourceMode) {
      setSource(value);
      return;
    }

    const editor = editorRef.current;
    if (!editor) return;

    if (document.activeElement !== editor && editor.innerHTML !== value) {
      editor.innerHTML = value;
    }
  }, [sourceMode, value]);

  useEffect(() => {
    if (!fullscreen) return;

    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previous;
    };
  }, [fullscreen]);

  function emitVisualChange() {
    const html = editorRef.current?.innerHTML ?? '';
    onChange(html);
  }

  function rememberSelection() {
    if (sourceMode) return;

    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return;

    const range = selection.getRangeAt(0);
    const editor = editorRef.current;

    if (editor && editor.contains(range.commonAncestorContainer)) {
      selectionRef.current = range.cloneRange();
    }
  }

  function restoreSelection() {
    const selection = window.getSelection();
    const range = selectionRef.current;

    if (!selection || !range) return;

    selection.removeAllRanges();
    selection.addRange(range);
  }

  function focusEditor() {
    editorRef.current?.focus();
    restoreSelection();
  }

  function runCommand(command: string, commandValue?: string) {
    if (disabled || sourceMode) return;

    focusEditor();
    document.execCommand(command, false, commandValue);
    emitVisualChange();
  }

  function setBlock(tag: string) {
    runCommand('formatBlock', '<' + tag + '>');
  }

  function createLink() {
    if (disabled || sourceMode) return;

    const url = window.prompt('Cole o endereço do link:');
    if (!url) return;

    const normalized = /^(https?:|mailto:|tel:|\/)/i.test(url)
      ? url
      : 'https://' + url;

    runCommand('createLink', normalized);

    editorRef.current
      ?.querySelectorAll<HTMLAnchorElement>('a[href="' + CSS.escape(normalized) + '"]')
      .forEach((anchor) => {
        if (/^https?:/i.test(normalized)) {
          anchor.target = '_blank';
          anchor.rel = 'noopener noreferrer';
        }
      });

    emitVisualChange();
  }

  function insertEditorialBlock(
    kind: 'context' | 'service' | 'numbers' | 'timeline' | 'keypoints' | 'sources' | 'update' | 'quote',
  ) {
    if (disabled || sourceMode) return;

    const blocks: Record<typeof kind, string> = {
      context: [
        '<aside data-nj-block="context">',
        '<strong>Entenda</strong>',
        '<p>Explique aqui o contexto necessário para o leitor compreender a notícia.</p>',
        '</aside>',
        '<p><br></p>',
      ].join(''),
      service: [
        '<aside data-nj-block="service">',
        '<strong>Serviço</strong>',
        '<p><b>Quando:</b> informe data e horário.</p>',
        '<p><b>Onde:</b> informe o local.</p>',
        '<p><b>Mais informações:</b> telefone, endereço ou link.</p>',
        '</aside>',
        '<p><br></p>',
      ].join(''),
      numbers: [
        '<aside data-nj-block="numbers">',
        '<strong>Em números</strong>',
        '<ul><li><b>0</b> — descreva o indicador.</li><li><b>0%</b> — acrescente outro dado.</li></ul>',
        '</aside>',
        '<p><br></p>',
      ].join(''),
      timeline: [
        '<aside data-nj-block="timeline">',
        '<strong>Cronologia</strong>',
        '<ul><li><b>00:00</b> — descreva o acontecimento.</li><li><b>00:00</b> — próximo marco.</li></ul>',
        '</aside>',
        '<p><br></p>',
      ].join(''),
      keypoints: [
        '<aside data-nj-block="keypoints">',
        '<strong>Principais pontos</strong>',
        '<ul><li>Primeiro ponto essencial da matéria.</li><li>Segundo ponto que o leitor precisa saber.</li><li>Terceiro ponto relevante.</li></ul>',
        '</aside>',
        '<p><br></p>',
      ].join(''),
      sources: [
        '<aside data-nj-block="sources">',
        '<strong>Fontes e documentos</strong>',
        '<ul><li><a href="https://">Fonte oficial ou documento</a></li><li><a href="https://">Fonte complementar</a></li></ul>',
        '</aside>',
        '<p><br></p>',
      ].join(''),
      update: [
        '<aside data-nj-block="update">',
        '<strong>Atualização</strong>',
        '<p><b>Horário:</b> 00:00</p>',
        '<p>Descreva o que mudou ou a nova informação confirmada.</p>',
        '</aside>',
        '<p><br></p>',
      ].join(''),
      quote: [
        '<blockquote data-nj-block="quote">',
        '<p>Insira aqui a declaração em destaque.</p>',
        '<cite>Nome da fonte — cargo ou contexto</cite>',
        '</blockquote>',
        '<p><br></p>',
      ].join(''),
    };

    runCommand('insertHTML', blocks[kind]);
  }

  function insertTable() {
    if (disabled || sourceMode) return;

    const rows = Math.min(12, Math.max(1, Number.parseInt(window.prompt('Número de linhas:', '3') ?? '0', 10)));
    const columns = Math.min(8, Math.max(1, Number.parseInt(window.prompt('Número de colunas:', '3') ?? '0', 10)));

    if (!Number.isFinite(rows) || !Number.isFinite(columns)) return;

    const cells = Array.from({ length: rows }, (_, rowIndex) => {
      const tag = rowIndex === 0 ? 'th' : 'td';
      return '<tr>'
        + Array.from({ length: columns }, () => '<' + tag + '>&nbsp;</' + tag + '>').join('')
        + '</tr>';
    }).join('');

    runCommand(
      'insertHTML',
      '<table><tbody>' + cells + '</tbody></table><p><br></p>',
    );
  }

  async function searchMedia(query = mediaQuery) {
    if (!loadMedia) return;

    setMediaLoading(true);
    setMediaError(false);

    try {
      const items = await loadMedia(query);
      setMediaItems(items);
    } catch {
      setMediaError(true);
    } finally {
      setMediaLoading(false);
    }
  }

  async function openMedia() {
    if (!loadMedia || disabled || sourceMode) return;

    rememberSelection();
    setMediaOpen(true);

    if (mediaItems.length === 0) {
      await searchMedia('');
    }
  }

  function insertMedia(item: RichEditorMediaItem) {
    const html = [
      '<figure>',
      '<img src="' + escapeHtml(item.url) + '" alt="' + escapeHtml(item.alt || item.title) + '">',
      '</figure>',
      '<p><br></p>',
    ].join('');

    runCommand('insertHTML', html);
    setMediaOpen(false);
  }

  function handlePaste(event: ClipboardEvent<HTMLDivElement>) {
    if (disabled || sourceMode) return;

    const html = event.clipboardData.getData('text/html');
    const text = event.clipboardData.getData('text/plain');

    event.preventDefault();

    if (html) {
      runCommand('insertHTML', sanitizePastedHtml(html));
      return;
    }

    runCommand(
      'insertHTML',
      escapeHtml(text).replace(/\r?\n/g, '<br>'),
    );
  }

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement | HTMLTextAreaElement>) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      if (canSave && onSave) onSave();
    }
  }

  function toggleSourceMode() {
    if (sourceMode) {
      onChange(source);
      setSourceMode(false);
      requestAnimationFrame(() => {
        if (editorRef.current) {
          editorRef.current.innerHTML = source;
        }
      });
      return;
    }

    setSource(value);
    setSourceMode(true);
  }

  return (
    <section
      className={
        'admin-rich-editor'
        + (fullscreen ? ' admin-rich-editor--fullscreen' : '')
        + (disabled ? ' admin-rich-editor--disabled' : '')
      }
    >
      <header className="admin-rich-editor__topbar">
        <div>
          <span>{label}</span>
          <strong>{sourceMode ? 'HTML' : 'Editor visual'}</strong>
        </div>

        <div className="admin-rich-editor__view-actions">
          <button
            type="button"
            className={sourceMode ? '' : 'active'}
            disabled={disabled}
            onClick={() => sourceMode && toggleSourceMode()}
          >
            Visual
          </button>
          <button
            type="button"
            className={sourceMode ? 'active' : ''}
            disabled={disabled}
            onClick={() => !sourceMode && toggleSourceMode()}
          >
            HTML
          </button>
          <button
            type="button"
            onClick={() => setFullscreen((current) => !current)}
          >
            {fullscreen ? 'Sair da tela cheia' : 'Tela cheia'}
          </button>
        </div>
      </header>

      {!sourceMode && (
        <div className="admin-rich-editor__ribbon" aria-label="Ferramentas de formatação">
          <div className="admin-rich-editor__group">
            <span>Estilo</span>
            <select
              aria-label="Estilo do parágrafo"
              disabled={disabled}
              defaultValue="p"
              onChange={(event) => {
                setBlock(event.target.value);
                event.currentTarget.value = 'p';
              }}
            >
              <option value="p">Texto normal</option>
              <option value="h2">Título 2</option>
              <option value="h3">Título 3</option>
              <option value="h4">Título 4</option>
              <option value="blockquote">Citação</option>
            </select>
          </div>

          <div className="admin-rich-editor__group">
            <span>Texto</span>
            <div className="admin-rich-editor__buttons">
              <AdminCommandButton label="B" title="Negrito (Ctrl+B)" disabled={disabled} onClick={() => runCommand('bold')} className="strong" />
              <AdminCommandButton label="I" title="Itálico (Ctrl+I)" disabled={disabled} onClick={() => runCommand('italic')} className="italic" />
              <AdminCommandButton label="U" title="Sublinhado (Ctrl+U)" disabled={disabled} onClick={() => runCommand('underline')} className="underline" />
              <AdminCommandButton label="S" title="Tachado" disabled={disabled} onClick={() => runCommand('strikeThrough')} className="strike" />
              <label className="admin-rich-editor__color" title="Cor do texto">
                A
                <input
                  type="color"
                  disabled={disabled}
                  defaultValue="#111827"
                  onChange={(event) => runCommand('foreColor', event.target.value)}
                />
              </label>
              <label className="admin-rich-editor__color admin-rich-editor__highlight" title="Marca-texto">
                ▰
                <input
                  type="color"
                  disabled={disabled}
                  defaultValue="#FFF59D"
                  onChange={(event) => runCommand('hiliteColor', event.target.value)}
                />
              </label>
            </div>
          </div>

          <div className="admin-rich-editor__group">
            <span>Parágrafo</span>
            <div className="admin-rich-editor__buttons">
              <AdminCommandButton label="≡" title="Alinhar à esquerda" disabled={disabled} onClick={() => runCommand('justifyLeft')} />
              <AdminCommandButton label="≣" title="Centralizar" disabled={disabled} onClick={() => runCommand('justifyCenter')} />
              <AdminCommandButton label="≡" title="Alinhar à direita" disabled={disabled} onClick={() => runCommand('justifyRight')} className="align-right" />
              <AdminCommandButton label="☰" title="Justificar" disabled={disabled} onClick={() => runCommand('justifyFull')} />
              <AdminCommandButton label="•" title="Lista com marcadores" disabled={disabled} onClick={() => runCommand('insertUnorderedList')} />
              <AdminCommandButton label="1." title="Lista numerada" disabled={disabled} onClick={() => runCommand('insertOrderedList')} />
              <AdminCommandButton label="←" title="Diminuir recuo" disabled={disabled} onClick={() => runCommand('outdent')} />
              <AdminCommandButton label="→" title="Aumentar recuo" disabled={disabled} onClick={() => runCommand('indent')} />
            </div>
          </div>

          <div className="admin-rich-editor__group">
            <span>Inserir</span>
            <div className="admin-rich-editor__buttons">
              <AdminCommandButton label="🔗" title="Inserir link" disabled={disabled} onClick={createLink} />
              <AdminCommandButton label="×🔗" title="Remover link" disabled={disabled} onClick={() => runCommand('unlink')} />
              <AdminCommandButton label="Imagem" title="Inserir imagem da biblioteca" disabled={disabled || !loadMedia} onClick={() => void openMedia()} className="wide" />
              <AdminCommandButton label="Tabela" title="Inserir tabela" disabled={disabled} onClick={insertTable} className="wide" />
              <AdminCommandButton label="―" title="Inserir linha horizontal" disabled={disabled} onClick={() => runCommand('insertHorizontalRule')} />
            </div>
          </div>

          <div className="admin-rich-editor__group">
            <span>Blocos</span>
            <div className="admin-rich-editor__buttons">
              <AdminCommandButton label="Entenda" title="Inserir box de contexto" disabled={disabled} onClick={() => insertEditorialBlock('context')} className="wide" />
              <AdminCommandButton label="Serviço" title="Inserir box de serviço" disabled={disabled} onClick={() => insertEditorialBlock('service')} className="wide" />
              <AdminCommandButton label="Números" title="Inserir box de números" disabled={disabled} onClick={() => insertEditorialBlock('numbers')} className="wide" />
              <AdminCommandButton label="Cronologia" title="Inserir cronologia" disabled={disabled} onClick={() => insertEditorialBlock('timeline')} className="wide" />
              <AdminCommandButton label="Pontos-chave" title="Inserir lista de principais pontos" disabled={disabled} onClick={() => insertEditorialBlock('keypoints')} className="wide" />
              <AdminCommandButton label="Fontes" title="Inserir box de fontes e documentos" disabled={disabled} onClick={() => insertEditorialBlock('sources')} className="wide" />
              <AdminCommandButton label="Atualização" title="Inserir atualização de cobertura" disabled={disabled} onClick={() => insertEditorialBlock('update')} className="wide" />
              <AdminCommandButton label="Citação" title="Inserir citação editorial destacada" disabled={disabled} onClick={() => insertEditorialBlock('quote')} className="wide" />
            </div>
          </div>

          <div className="admin-rich-editor__group">
            <span>Edição</span>
            <div className="admin-rich-editor__buttons">
              <AdminCommandButton label="↶" title="Desfazer (Ctrl+Z)" disabled={disabled} onClick={() => runCommand('undo')} />
              <AdminCommandButton label="↷" title="Refazer (Ctrl+Y)" disabled={disabled} onClick={() => runCommand('redo')} />
              <AdminCommandButton label="Tx" title="Limpar formatação" disabled={disabled} onClick={() => runCommand('removeFormat')} />
            </div>
          </div>
        </div>
      )}

      {sourceMode ? (
        <textarea
          className="admin-rich-editor__source"
          value={source}
          readOnly={disabled}
          spellCheck={false}
          style={{ minHeight }}
          onChange={(event) => {
            setSource(event.target.value);
            onChange(event.target.value);
          }}
          onKeyDown={handleKeyDown}
        />
      ) : (
        <div
          ref={editorRef}
          className="admin-rich-editor__canvas"
          contentEditable={!disabled}
          suppressContentEditableWarning
          spellCheck
          style={{ minHeight }}
          onInput={() => {
            emitVisualChange();
            rememberSelection();
          }}
          onPaste={handlePaste}
          onKeyDown={handleKeyDown}
          onKeyUp={rememberSelection}
          onMouseUp={rememberSelection}
          onFocus={rememberSelection}
          aria-label={label}
        />
      )}

      <footer className="admin-rich-editor__statusbar">
        <span>{wordCount.toLocaleString('pt-BR')} palavras</span>
        <span>{characterCount.toLocaleString('pt-BR')} caracteres</span>
        <span>{readingMinutes ? readingMinutes + ' min de leitura' : 'tempo de leitura —'}</span>
        <span>{structureStats.headings} subtítulos</span>
        <span>{structureStats.links} links</span>
        <span>{structureStats.images} imagens</span>
        <span>Ctrl+S salva</span>
      </footer>

      {mediaOpen && (
        <div className="admin-rich-editor__media-layer" role="dialog" aria-modal="true" aria-label="Inserir imagem">
          <div className="admin-rich-editor__media-dialog">
            <header>
              <div>
                <span>Biblioteca de mídia</span>
                <h3>Inserir imagem no conteúdo</h3>
              </div>
              <button type="button" onClick={() => setMediaOpen(false)} aria-label="Fechar">×</button>
            </header>

            <form
              className="admin-rich-editor__media-search"
              onSubmit={(event: FormEvent) => {
                event.preventDefault();
                void searchMedia(mediaQuery);
              }}
            >
              <input
                type="search"
                value={mediaQuery}
                placeholder="Buscar imagem"
                onChange={(event) => setMediaQuery(event.target.value)}
              />
              <button type="submit" disabled={mediaLoading}>
                {mediaLoading ? 'Buscando…' : 'Buscar'}
              </button>
            </form>

            {mediaError && (
              <p className="admin-rich-editor__media-error">Não foi possível carregar a biblioteca.</p>
            )}

            <div className="admin-rich-editor__media-grid">
              {mediaItems.map((item) => (
                <button
                  type="button"
                  key={item.id}
                  onClick={() => insertMedia(item)}
                >
                  <img src={item.url} alt={item.alt || item.title} loading="lazy" />
                  <span>{item.title}</span>
                </button>
              ))}
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
