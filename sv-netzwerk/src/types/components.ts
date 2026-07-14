export type Alignment = 'left' | 'center';
export type GridColumns = 2 | 3 | 4;
export type CardTone = 'default' | 'soft' | 'dark' | 'accent';

export interface LinkAction {
  href: string;
  label?: string;
  external?: boolean;
}

export interface FeatureCardProps {
  eyebrow?: string;
  title: string;
  text: string;
  href?: string;
  linkLabel?: string;
  index?: string;
  tone?: CardTone;
}

export interface ServiceCardProps {
  number?: string;
  title: string;
  text: string;
  href: string;
  label?: string;
  tone?: CardTone;
}

export interface TeamCardProps {
  name: string;
  role: string;
  qualification?: string;
  href?: string;
  initials?: string;
  image?: string;
  imageAlt?: string;
}

export interface StatCardProps {
  value: string;
  label: string;
  text?: string;
  suffix?: string;
}

export interface TimelineItem {
  title: string;
  text: string;
  date?: string;
}

export interface FAQItem {
  question: string;
  answer: string;
}

export interface IconListItem {
  text: string;
  icon?: string;
}
