import EditorInsertModal from '@/Components/EditorInsertModal';
import MediaPickerModal from '@/Components/MediaPickerModal';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Image from '@tiptap/extension-image';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import { useEffect, useRef, useState } from 'react';

function ToolbarButton({ active, disabled, onClick, children, title }) {
    return (
        <button
            type="button"
            title={title}
            disabled={disabled}
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className={
                'rounded-md px-2 py-1 text-sm font-semibold transition disabled:opacity-40 ' +
                (active
                    ? 'bg-signal-soft text-signal-strong'
                    : 'text-ink-muted hover:bg-mist hover:text-ink')
            }
        >
            {children}
        </button>
    );
}

function Divider() {
    return (
        <span
            className="mx-1 hidden h-5 w-px bg-line sm:inline-block"
            aria-hidden
        />
    );
}

export default function RichTextEditor({
    value = '',
    onChange,
    placeholder = 'Write your post…',
    id = 'body',
}) {
    const editorRef = useRef(null);
    const lastEmittedHtml = useRef(value || '');
    const [linkModal, setLinkModal] = useState(null);
    const [mediaOpen, setMediaOpen] = useState(false);

    const editor = useEditor({
        immediatelyRender: false,
        shouldRerenderOnTransaction: true,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3] },
                codeBlock: {
                    HTMLAttributes: {
                        class: 'rounded-lg bg-mist px-3 py-2 font-mono text-sm',
                    },
                },
            }),
            Underline,
            Highlight.configure({
                multicolor: false,
                HTMLAttributes: {
                    class: 'rounded px-0.5 bg-signal-soft',
                },
            }),
            TextAlign.configure({
                types: ['heading', 'paragraph'],
            }),
            Link.configure({
                openOnClick: false,
                HTMLAttributes: {
                    class: 'text-signal-strong underline',
                    rel: 'noopener noreferrer',
                    target: '_blank',
                },
            }),
            Image.configure({
                allowBase64: false,
                HTMLAttributes: {
                    class: 'editor-inline-image',
                },
            }),
            Placeholder.configure({ placeholder }),
        ],
        content: value || '',
        editorProps: {
            attributes: {
                id,
                class: 'tiptap rich-content min-h-[18rem] max-w-full overflow-x-hidden px-3 py-3 text-ink focus:outline-none sm:min-h-[28rem]',
            },
        },
        onCreate: ({ editor: created }) => {
            editorRef.current = created;
        },
        onUpdate: ({ editor: current }) => {
            editorRef.current = current;
            const html = current.isEmpty ? '' : current.getHTML();
            lastEmittedHtml.current = html;
            onChange?.(html);
        },
    });

    useEffect(() => {
        editorRef.current = editor;
    }, [editor]);

    useEffect(() => {
        if (!editor) {
            return;
        }

        const next = value || '';
        if (next === lastEmittedHtml.current) {
            return;
        }

        lastEmittedHtml.current = next;
        editor.commands.setContent(next, { emitUpdate: false });
    }, [value, editor]);

    if (!editor) {
        return (
            <div className="flex min-h-[18rem] items-center justify-center rounded-md border border-line bg-white text-sm text-ink-muted">
                Loading editor…
            </div>
        );
    }

    const run = (command) => {
        command(editor.chain().focus()).run();
    };

    const openLinkModal = () => {
        setLinkModal({
            initialUrl: editor.getAttributes('link').href || '',
        });
    };

    const handleLinkSubmit = ({ url }) => {
        if (!url) {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
        } else {
            editor
                .chain()
                .focus()
                .extendMarkRange('link')
                .setLink({ href: url })
                .run();
        }
        setLinkModal(null);
    };

    const insertMedia = (assets) => {
        const current = editorRef.current;
        if (!current || !assets?.length) {
            return;
        }
        let chain = current.chain().focus();
        assets.forEach((asset) => {
            const src = asset.url || asset.thumb_url;
            if (src) {
                chain = chain.setImage({
                    src,
                    alt: asset.name || '',
                    title: asset.name || '',
                });
            }
        });
        chain.run();
    };

    return (
        <div className="max-w-full overflow-hidden rounded-md border border-line bg-white shadow-sm focus-within:border-signal focus-within:ring-1 focus-within:ring-signal">
            <div
                className="flex flex-wrap items-center gap-0.5 border-b border-line bg-mist/60 px-2 py-1.5"
                onMouseDown={(e) => e.preventDefault()}
            >
                <ToolbarButton
                    title="Bold"
                    active={editor.isActive('bold')}
                    onClick={() => run((c) => c.toggleBold())}
                >
                    B
                </ToolbarButton>
                <ToolbarButton
                    title="Italic"
                    active={editor.isActive('italic')}
                    onClick={() => run((c) => c.toggleItalic())}
                >
                    <span className="italic">I</span>
                </ToolbarButton>
                <ToolbarButton
                    title="Underline"
                    active={editor.isActive('underline')}
                    onClick={() => run((c) => c.toggleUnderline())}
                >
                    <span className="underline">U</span>
                </ToolbarButton>
                <ToolbarButton
                    title="Strike"
                    active={editor.isActive('strike')}
                    onClick={() => run((c) => c.toggleStrike())}
                >
                    <span className="line-through">S</span>
                </ToolbarButton>
                <ToolbarButton
                    title="Highlight"
                    active={editor.isActive('highlight')}
                    onClick={() => run((c) => c.toggleHighlight())}
                >
                    HL
                </ToolbarButton>

                <Divider />

                <ToolbarButton
                    title="Heading"
                    active={editor.isActive('heading', { level: 2 })}
                    onClick={() => run((c) => c.toggleHeading({ level: 2 }))}
                >
                    H2
                </ToolbarButton>
                <ToolbarButton
                    title="Subheading"
                    active={editor.isActive('heading', { level: 3 })}
                    onClick={() => run((c) => c.toggleHeading({ level: 3 }))}
                >
                    H3
                </ToolbarButton>

                <Divider />

                <ToolbarButton
                    title="Align left"
                    active={editor.isActive({ textAlign: 'left' })}
                    onClick={() => run((c) => c.setTextAlign('left'))}
                >
                    Left
                </ToolbarButton>
                <ToolbarButton
                    title="Align center"
                    active={editor.isActive({ textAlign: 'center' })}
                    onClick={() => run((c) => c.setTextAlign('center'))}
                >
                    Center
                </ToolbarButton>
                <ToolbarButton
                    title="Align right"
                    active={editor.isActive({ textAlign: 'right' })}
                    onClick={() => run((c) => c.setTextAlign('right'))}
                >
                    Right
                </ToolbarButton>

                <Divider />

                <ToolbarButton
                    title="Bullet list"
                    active={editor.isActive('bulletList')}
                    onClick={() => run((c) => c.toggleBulletList())}
                >
                    • List
                </ToolbarButton>
                <ToolbarButton
                    title="Numbered list"
                    active={editor.isActive('orderedList')}
                    onClick={() => run((c) => c.toggleOrderedList())}
                >
                    1. List
                </ToolbarButton>
                <ToolbarButton
                    title="Quote"
                    active={editor.isActive('blockquote')}
                    onClick={() => run((c) => c.toggleBlockquote())}
                >
                    Quote
                </ToolbarButton>
                <ToolbarButton
                    title="Code block"
                    active={editor.isActive('codeBlock')}
                    onClick={() => run((c) => c.toggleCodeBlock())}
                >
                    Code
                </ToolbarButton>
                <ToolbarButton
                    title="Divider"
                    onClick={() => run((c) => c.setHorizontalRule())}
                >
                    ―
                </ToolbarButton>

                <Divider />

                <ToolbarButton
                    title="Link"
                    active={editor.isActive('link')}
                    onClick={openLinkModal}
                >
                    Link
                </ToolbarButton>
                <ToolbarButton
                    title="Image from Media"
                    active={editor.isActive('image')}
                    onClick={() => setMediaOpen(true)}
                >
                    Image
                </ToolbarButton>
                {editor.isActive('image') && (
                    <ToolbarButton
                        title="Remove selected image"
                        onClick={() => run((c) => c.deleteSelection())}
                    >
                        Remove image
                    </ToolbarButton>
                )}
                <ToolbarButton
                    title="Clear formatting"
                    onClick={() => run((c) => c.unsetAllMarks().clearNodes())}
                >
                    Clear
                </ToolbarButton>
            </div>

            <EditorContent editor={editor} />

            <EditorInsertModal
                show={Boolean(linkModal)}
                mode="link"
                initialUrl={linkModal?.initialUrl || ''}
                onClose={() => setLinkModal(null)}
                onSubmit={handleLinkSubmit}
            />

            <MediaPickerModal
                show={mediaOpen}
                onClose={() => setMediaOpen(false)}
                onSelect={insertMedia}
            />
        </div>
    );
}
