export type InsurerClient = {
  name: string;
  logo?: string;
  logoAlt?: string;
};

export const insurerClients: InsurerClient[] = [
  { name: 'SV Sparkassenversicherung', logo: '/assets/images/insurers/sparkassenversicherung.png', logoAlt: 'SV Sparkassenversicherung' },
  { name: 'R+V Versicherung', logo: '/assets/images/insurers/ruv-versicherung.svg', logoAlt: 'R+V Versicherung' },
  { name: 'ERGO Versicherung', logo: '/assets/images/insurers/ergo.svg', logoAlt: 'ERGO Versicherung' },
  { name: 'Württembergische Versicherung', logo: '/assets/images/insurers/wuerttembergische.svg', logoAlt: 'Württembergische Versicherung' },
  { name: 'LVM Versicherung', logo: '/assets/images/insurers/lvm.svg', logoAlt: 'LVM Versicherung' },
  { name: 'Concordia Versicherungen', logo: '/assets/images/insurers/concordia.png', logoAlt: 'Concordia Versicherungen' },
  { name: 'Alte Leipziger' },
  { name: 'Helvetia Versicherung', logo: '/assets/images/insurers/helvetia.svg', logoAlt: 'Helvetia Versicherung' },
  { name: 'Provinzial Versicherung', logo: '/assets/images/insurers/provinzial.svg', logoAlt: 'Provinzial Versicherung' },
  { name: 'Ecclesia Gruppe', logo: '/assets/images/insurers/ecclesia-gruppe.png', logoAlt: 'Ecclesia Gruppe' },
];
