"use client";

import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { i18nFor } from "@/lib/i18n";
import { useI18n } from "@/lib/i18n/context";
import { preferredLocale } from "@/lib/i18n/locales";
import { GAP_PATTERN } from "@/lib/markers";

const GAP_HREF = "#tbd-gap";

function prepare(content: string) {
  return content.replace(GAP_PATTERN, (_, text: string) => {
    const clean = (text || "something").replace(/[[\]]/g, "").trim();
    return `[${clean}](${GAP_HREF})`;
  });
}

type Props = { content: string; className?: string; language?: string | null };

export function Markdown({ content, className, language = null }: Props) {
  const { locale } = useI18n();
  const { t } = i18nFor(preferredLocale(language) ?? locale);
  return (
    <div lang={language ?? undefined} className={`prose-ripe ${className ?? ""}`}>
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          a({ href, children }) {
            if (href === GAP_HREF) {
              return (
                <mark className="gap-chip" title={t.markdown.gapTitle}>
                  {t.markdown.gap}: {children}
                </mark>
              );
            }
            return (
              <a
                href={href}
                target="_blank"
                rel="noreferrer"
                className="underline decoration-line-2 underline-offset-2"
              >
                {children}
              </a>
            );
          },
          input(props) {
            return <input {...props} disabled readOnly />;
          },
        }}
      >
        {prepare(content)}
      </ReactMarkdown>
    </div>
  );
}
